.Parameters
| map({key: (.Name | ltrimstr($prefix)), value: .Value}) as $entries
| if ($entries | length) == 0 then error("No SSM parameters found") else . end
| if all($entries[]; .key | test("^[A-Z][A-Z0-9_]*$")) then .
  else error("SSM parameters must be immediate uppercase environment-variable names") end
| if ($entries | map(.key) | unique | length) == ($entries | length) then .
  else error("Duplicate SSM environment-variable names") end
| if all($entries[]; (.value | type) == "string") then .
  else error("SSM parameter values must be strings") end
| if all($entries[]; .value | contains("\n") or contains("\r") or contains("\u0000") | not) then .
  else error("Multiline and NUL-containing secrets are not supported") end
| ($entries | from_entries) as $secrets
| if ($secrets.APP_KEY // "" | test("^base64:[A-Za-z0-9+/]{43}=$")) then .
  else error("A persistent AES-256 APP_KEY is required") end
| if ($secrets.DB_PASSWORD // "" | length) >= 20
     and ($secrets.MYSQL_ROOT_PASSWORD // "" | length) >= 20
     and $secrets.DB_PASSWORD != $secrets.MYSQL_ROOT_PASSWORD then .
  else error("Distinct database and root passwords of at least 20 characters are required") end
| if all([$secrets.DB_PASSWORD, $secrets.MYSQL_ROOT_PASSWORD][];
         test("^[!-~]+$") and (test("[\"'\\\\]") | not)) then .
  else error("Database passwords must be printable ASCII without spaces, quotes, or backslashes; random hex is recommended") end
| if $target == "app" then
    ($public[0] + $secrets | del(.MYSQL_ROOT_PASSWORD)
     | to_entries | map(.key + "=" + .value) | join("\n"))
  else
    "MYSQL_DATABASE=" + $database + "\n"
    + "MYSQL_USER=" + $username + "\n"
    + "MYSQL_PASSWORD=" + $secrets.DB_PASSWORD + "\n"
    + "MYSQL_ROOT_PASSWORD=" + $secrets.MYSQL_ROOT_PASSWORD
  end