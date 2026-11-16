"""Read-only hosting inventory for Gordey_ap01. Credentials arrive only on stdin."""
import ftplib
import getpass
import io
import json
import ssl
import sys

import requests

credentials = {"login": "b9141846", "password": getpass.getpass("Beget password (hidden): ")}
session = requests.Session()
for method in ("site/getList", "domain/getList", "user/getAccountInfo"):
    try:
        response = session.post(
            "https://api.beget.com/api/" + method,
            data={"login": credentials["login"], "passwd": credentials["password"], "output_format": "json"},
            timeout=15, allow_redirects=False,
        )
        if "json" not in response.headers.get("Content-Type", ""):
            print(json.dumps({"method": method, "status": response.status_code, "location": response.headers.get("Location"), "content_type": response.headers.get("Content-Type")}), flush=True)
            continue
        data = response.json()
        if method == "user/getAccountInfo" and data.get("answer", {}).get("result"):
            value = data["answer"]["result"]
            data = {"status": data.get("status"), "answer_status": data["answer"].get("status"), "fields": list(value), "server_name": value.get("server_name"), "server_ip": value.get("server_ip")}
        print(json.dumps({"method": method, "result": data}, ensure_ascii=False), flush=True)
    except Exception as error:
        print(json.dumps({"method": method, "error_type": type(error).__name__}), flush=True)
try:
    ftp = ftplib.FTP_TLS(context=ssl.create_default_context(), timeout=15)
    ftp.connect("b9141846.beget.tech", 21)
    ftp.auth()
    ftp.login(credentials["login"], credentials["password"])
    ftp.prot_p()
    entries = []
    ftp.retrlines("LIST", entries.append)
    print(json.dumps({"ftps": "verified", "entries": entries}, ensure_ascii=False), flush=True)
    ftp.quit()
except Exception as error:
    print(json.dumps({"ftps": "failed", "error_type": type(error).__name__, "reason": str(error)[:200]}, ensure_ascii=False), flush=True)
