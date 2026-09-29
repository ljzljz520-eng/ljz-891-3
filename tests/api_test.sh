#!/usr/bin/env bash
# 端到端 API 测试。用法：先启动服务（scripts/serve.sh），再 bash tests/api_test.sh
set -u
BASE="http://127.0.0.1:8080"
WORKSPACE="$(cd "$(dirname "$0")/.." && pwd)"
PHP="$WORKSPACE/.tools/php"
[ -x "$PHP" ] || PHP="php"
export WS="$WORKSPACE"

JAR_C=/tmp/c_cookies.txt; JAR_A=/tmp/a_cookies.txt
PASS=0; FAIL=0
rm -f "$JAR_C" "$JAR_A"

check() {
  if [ "$2" == "$3" ]; then echo "  ✓ $1"; PASS=$((PASS+1));
  else echo "  ✗ $1 (期望:$2 实际:$3)"; FAIL=$((FAIL+1)); fi
}

db_exec() { "$PHP" "$WORKSPACE/tests/db_helper.php" exec "$1"; }
db_query() { "$PHP" "$WORKSPACE/tests/db_helper.php" query "$@"; }

# 申请一个验证码，并从服务端数据库读出答案（测试中模拟“人眼识别”）
challenge() {
  local c id
  if [ -n "${1:-}" ]; then
    c=$(curl -s $1 "$BASE/api/captcha.php")   # 形如 -b /tmp/x -c /tmp/x
  else
    c=$(curl -s "$BASE/api/captcha.php")
  fi
  id=$(echo "$c" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['captcha_id'])")
  CH_ID="$id"
  CH_ANS=$("$PHP" "$WORKSPACE/tests/db_helper.php" query 'SELECT answer FROM captchas WHERE id = ?' "$id")
}

reset_limits() { db_exec 'DELETE FROM rate_hits; DELETE FROM captchas;'; }

post_json() { # url json [cookie] -> body 写入 /tmp/api_body，状态码写入全局 ST
  local args=(-s -o /tmp/api_body -w '%{http_code}' -X POST "$1" -H 'Content-Type: application/json' -d "$2")
  [ -n "${3:-}" ] && args=(-b "$3" -c "$3" "${args[@]}")
  ST=$(curl "${args[@]}")
}
put_json() { # url json cookie  -> body 写入 /tmp/api_body，状态码写入 ST
  ST=$(curl -s -o /tmp/api_body -w '%{http_code}' -b "$3" -c "$3" -X PUT "$1" \
    -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -d "$2")
}
get_code() { curl -s -o /tmp/api_body -w '%{http_code}' "${@:2}" "$1"; }

echo "== 0. 预热，触发建库/种子 =="
curl -s -o /dev/null "$BASE/api/captcha.php"
reset_limits

echo "== 1. 验证码接口 =="
C=$(curl -s -b "$JAR_C" -c "$JAR_C" "$BASE/api/captcha.php")
echo "$C" | python3 -c "import json,sys;d=json.load(sys.stdin);assert d['ok'] and '?' in d['data']['question'] and d['data']['ttl']==300"
check "获取验证码成功" 0 $?
CH_ID=$(echo "$C" | python3 -c "import json,sys;print(json.load(sys.stdin)['data']['captcha_id'])")
CH_ANS=$(db_query 'SELECT answer FROM captchas WHERE id = ?' "$CH_ID")

echo "== 2. 参数校验 =="
ST=$(get_code "$BASE/api/query.php" -X POST -H 'Content-Type: application/json' -d '{}')
check "空参数 400" 400 "$ST"
ST=$(get_code "$BASE/api/query.php" -X POST -H 'Content-Type: application/json' \
  -d '{"order_no":"XX","phone":"123","captcha_id":"x","captcha_answer":"1"}')
check "格式错误 400" 400 "$ST"

echo "== 3. 错误验证码 =="
ST=$(get_code "$BASE/api/query.php" -X POST -H 'Content-Type: application/json' \
  -d "{\"order_no\":\"RP7K2M9QX4NT\",\"phone\":\"13812345678\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"-999\"}")
check "验证码错误 400" 400 "$ST"

echo "== 4. 验证码一次性（重放） =="
ST=$(get_code "$BASE/api/query.php" -X POST -H 'Content-Type: application/json' \
  -d "{\"order_no\":\"RP7K2M9QX4NT\",\"phone\":\"13812345678\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}")
check "验证码重放被拒 400" 400 "$ST"
reset_limits

echo "== 5. 正常查询成功（小写订单号/带格式手机号也应通过） =="
challenge
post_json "$BASE/api/query.php" \
  "{\"order_no\":\"rp7k2m9qx4nt\",\"phone\":\"+86 138-1234-5678\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
check "查询 200" 200 "$ST"
cp /tmp/api_body /tmp/q1.json
python3 - <<'PY'
import json
d=json.load(open('/tmp/q1.json'))['data']
assert d['order_no']=='RP7K2M9QX4NT'
assert d['deposit_amount']=='8000.00'
assert d['deposit_status']=='held' and d['rental_status']=='renting'
assert len(d['equipment'])==4
assert d['equipment'][0]['name']=='索尼全画幅微单'
assert 'customer_name' not in d and 'abnormal_note' not in d and 'phone' not in d
print('  ✓ 押金/状态/设备清单正确且无内部/个人字段')
PY

echo "== 6. 错误手机号（订单存在但不匹配） =="
reset_limits; challenge
post_json "$BASE/api/query.php" \
  "{\"order_no\":\"RP7K2M9QX4NT\",\"phone\":\"13700000000\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
check "手机号错误 404" 404 "$ST"
grep -q '订单号与手机号不匹配' /tmp/api_body && echo "  ✓ 统一笼统提示"

echo "== 7. 不存在订单（相同口径、相同状态码） =="
reset_limits; challenge
post_json "$BASE/api/query.php" \
  "{\"order_no\":\"RPZZ9999ZZZZ\",\"phone\":\"13812345678\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
check "订单不存在 404" 404 "$ST"
cp /tmp/api_body /tmp/q3.json
python3 - <<'PY'
import json
b=json.load(open('/tmp/q3.json'))
assert b['code']=='not_found' and '不匹配' in b['message']
print('  ✓ 不泄露“订单是否存在”')
PY

echo "== 8. 连续 5 次失败后锁定，正确凭证也被拦 =="
reset_limits
for i in 1 2 3 4 5; do
  challenge
  post_json "$BASE/api/query.php" \
    "{\"order_no\":\"RP7K2M9QX4NT\",\"phone\":\"13700000000\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
done
check "第5次返回 429" 429 "$ST"
challenge
post_json "$BASE/api/query.php" \
  "{\"order_no\":\"RP7K2M9QX4NT\",\"phone\":\"13812345678\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
check "锁定期正确凭证 429" 429 "$ST"
reset_limits

echo "== 9. 已归还已退款订单 =="
challenge
post_json "$BASE/api/query.php" \
  "{\"order_no\":\"RP4W8X3F6VZK\",\"phone\":\"13998765432\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
cp /tmp/api_body /tmp/q5.json
check "查询 200" 200 "$ST"
python3 - <<'PY'
import json
d=json.load(open('/tmp/q5.json'))['data']
assert d['rental_status']=='returned' and d['deposit_status']=='refunded'
assert d['refund_time']=='2026-09-17 11:20:00' and d['return_time']=='2026-09-15 19:42:00'
assert len(d['equipment'])==3
print('  ✓ 归还状态/退款时间/清单正确')
PY

echo "== 10. 后台未登录访问 =="
ST=$(get_code "$BASE/api/admin/orders.php")
check "订单列表 401" 401 "$ST"
ST=$(get_code "$BASE/api/admin/logs.php?format=csv")
check "日志导出 401" 401 "$ST"

echo "== 11. 后台登录：错误密码 / 正确登录 =="
reset_limits; rm -f "$JAR_A"
challenge "-b $JAR_A -c $JAR_A"
post_json "$BASE/api/admin/login.php" \
  "{\"username\":\"admin\",\"password\":\"wrongpass\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}" "$JAR_A"
check "错误密码 401" 401 "$ST"

# 连续失败到第 5 次应直接锁定
for i in 2 3 4; do
  challenge "-b $JAR_A -c $JAR_A"
  post_json "$BASE/api/admin/login.php" \
    "{\"username\":\"admin\",\"password\":\"wrongpass\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}" "$JAR_A"
  check "第 $i 次错误密码 401" 401 "$ST"
done
challenge "-b $JAR_A -c $JAR_A"
post_json "$BASE/api/admin/login.php" \
  "{\"username\":\"admin\",\"password\":\"wrongpass\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}" "$JAR_A"
check "第 5 次错误密码触发锁定 429" 429 "$ST"
reset_limits

challenge "-b $JAR_A -c $JAR_A"
post_json "$BASE/api/admin/login.php" \
  "{\"username\":\"admin\",\"password\":\"Rent@2026\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}" "$JAR_A"
cp /tmp/api_body /tmp/login.json
check "正确登录 200" 200 "$ST"
CSRF=$(python3 -c "import json;print(json.load(open('/tmp/login.json'))['data']['csrf_token'])")
echo "$CSRF" | grep -Eq '^[a-f0-9]{64}$' && echo "  ✓ 下发 64 位 CSRF 令牌"
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" "$BASE/api/admin/me.php")
check "会话保持 200" 200 "$ST"

echo "== 12. CSRF 防护 =="
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" -X POST "$BASE/api/admin/orders.php" \
  -H 'Content-Type: application/json' -d '{}')
check "无 CSRF 头 403" 403 "$ST"
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" -X POST "$BASE/api/admin/orders.php" \
  -H 'Content-Type: application/json' -H 'X-CSRF-Token: deadbeefdeadbeef' -d '{}')
check "错误 CSRF 403" 403 "$ST"

echo "== 13. 录入租赁记录 =="
ST=$(curl -s -o /tmp/create.json -w '%{http_code}' -b "$JAR_A" -X POST "$BASE/api/admin/orders.php" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -d '{
    "name":"测试顾客","phone":"13511112222","deposit_amount":"1500.50",
    "rental_status":"renting","deposit_status":"held",
    "rental_start":"2026-09-28T10:00","rental_end":"2026-10-08T18:00",
    "items":[{"name":"稳定器","model":"DJI RS 4 Pro","qty":1,"unit_price":"120.00"},
             {"name":"补光灯","model":"Aputure 60d","qty":2,"unit_price":"45.5"}]
  }')
check "录入 200" 200 "$ST"
NEW_NO=$(python3 -c "import json;print(json.load(open('/tmp/create.json'))['data']['order_no'])")
echo "    新订单号: $NEW_NO"
echo "$NEW_NO" | grep -Eq '^RP[A-HJ-NP-Z2-9]{10}$' && echo "  ✓ 订单号随机不可顺序猜测"

echo "== 14. 非法录入被拒 =="
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" -X POST "$BASE/api/admin/orders.php" \
  -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" -d '{
    "name":"x","phone":"123","deposit_amount":"-1","rental_status":"renting",
    "deposit_status":"held","rental_start":"bad","items":[]}')
check "非法表单 400" 400 "$ST"

echo "== 15. 新订单客户可查 =="
reset_limits; challenge
post_json "$BASE/api/query.php" \
  "{\"order_no\":\"$NEW_NO\",\"phone\":\"13511112222\",\"captcha_id\":\"$CH_ID\",\"captcha_answer\":\"$CH_ANS\"}"
cp /tmp/api_body /tmp/q6.json
check "查询 200" 200 "$ST"
python3 - <<'PY'
import json
d=json.load(open('/tmp/q6.json'))['data']
assert d['deposit_amount']=='1500.50'
assert {(i['name'],i['qty'],i['daily_rent']) for i in d['equipment']}=={
  ('稳定器',1,'120.00'),('补光灯',2,'45.50')}
print('  ✓ 金额按分存储无精度问题，清单正确')
PY

echo "== 16. 标记异常 / 解除 / 编辑订单 =="
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" -X POST \
  "$BASE/api/admin/abnormal.php?order_no=$NEW_NO" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"is_abnormal":1,"note":""}')
check "异常无说明 400" 400 "$ST"
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" -X POST \
  "$BASE/api/admin/abnormal.php?order_no=$NEW_NO" -H 'Content-Type: application/json' -H "X-CSRF-Token: $CSRF" \
  -d '{"is_abnormal":1,"note":"补光灯灯罩轻微磕碰","deposit_status":"deducted"}')
check "标记异常 200" 200 "$ST"
put_json "$BASE/api/admin/orders.php?order_no=$NEW_NO" \
  '{"rental_status":"returned","deposit_status":"refunded","return_time":"","refund_time":"2026-09-30T15:00"}' "$JAR_A"
check "更新订单 200" 200 "$ST"
curl -s -b "$JAR_A" "$BASE/api/admin/orders.php?order_no=$NEW_NO" > /tmp/detail.json
python3 - <<'PY'
import json
o=json.load(open('/tmp/detail.json'))['data']['order']
assert o['is_abnormal']==1 and '灯罩' in o['abnormal_note']
assert o['rental_status']=='returned' and o['deposit_status']=='refunded'
assert o['return_time'], '归还时间应自动补全'
assert o['refund_time']=='2026-09-30 15:00:00'
print('  ✓ 异常说明仅后台可见；状态联动/时间自动补全正确')
PY

echo "== 17. 日志列表 / 筛选 / CSV 导出 =="
ST=$(curl -s -o /tmp/logs.json -w '%{http_code}' -b "$JAR_A" "$BASE/api/admin/logs.php?result=success")
check "日志列表 200" 200 "$ST"
python3 - <<'PY'
import json
d=json.load(open('/tmp/logs.json'))['data']
assert d['total']>=3 and all(l['result']=='success' for l in d['list'])
assert all(set(['13812345678']) & set() or l['phone_last4'] in ('','5678','5432','2222') for l in d['list'])
print('  ✓ 日志只记录手机号后四位')
PY
ST=$(curl -s -o /tmp/logs.csv -w '%{http_code}' -b "$JAR_A" "$BASE/api/admin/logs.php?format=csv")
check "CSV 导出 200" 200 "$ST"
head -c 3 /tmp/logs.csv | od -An -tx1 | grep -q 'ef bb bf' && echo "  ✓ CSV 带 UTF-8 BOM"
grep -q '查询成功' /tmp/logs.csv && echo "  ✓ CSV 中文正常"
if grep -Eq '13[0-9]{9}' /tmp/logs.csv; then echo "  ✗ CSV 出现完整手机号"; FAIL=$((FAIL+1));
else echo "  ✓ CSV 无完整手机号"; PASS=$((PASS+1)); fi

echo "== 18. 安全响应头与路径穿越 =="
curl -sI "$BASE/" | grep -qi 'x-content-type-options: nosniff' && { echo "  ✓ X-Content-Type-Options"; PASS=$((PASS+1)); }
curl -sI "$BASE/" | grep -qi 'x-frame-options: deny' && { echo "  ✓ X-Frame-Options"; PASS=$((PASS+1)); }
curl -sI "$BASE/" | grep -qi 'content-security-policy' && { echo "  ✓ CSP"; PASS=$((PASS+1)); }
for f in '/../config.php' '/../lib/helpers.php' '/../data/app.sqlite'; do
  ST=$(curl -s -o /dev/null -w '%{http_code}' --path-as-is "$BASE$f")
  check "$f 不可访问" 404 "$ST"
done

echo "== 19. 登出后会话失效 =="
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" -c "$JAR_A" -X POST "$BASE/api/admin/logout.php" \
  -H "X-CSRF-Token: $CSRF")
check "登出 200" 200 "$ST"
ST=$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR_A" "$BASE/api/admin/orders.php")
check "登出后访问 401" 401 "$ST"

echo
echo "===================="
echo "结果: PASS=$PASS FAIL=$FAIL"
[ "$FAIL" -eq 0 ]
