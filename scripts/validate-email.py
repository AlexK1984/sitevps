#!/usr/bin/env python3

import re
import sys

LOCAL_PART = re.compile(r"[A-Za-z0-9!#$%&'*+/=?^_`{|}~.-]+\Z")
DOMAIN_LABEL = re.compile(r"[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\Z")

try:
    email = sys.argv[1]
except IndexError:
    raise SystemExit(1)

if email.endswith("\r"):
    email = email[:-1]

if not email or len(email) > 254 or any(ord(char) < 33 or ord(char) == 127 for char in email):
    raise SystemExit(1)
if email.count("@") != 1:
    raise SystemExit(1)

local_part, domain = email.split("@")
labels = domain.split(".")
if (
    not local_part
    or len(local_part) > 64
    or local_part.startswith(".")
    or local_part.endswith(".")
    or ".." in local_part
    or LOCAL_PART.fullmatch(local_part) is None
    or len(domain) > 253
    or len(labels) < 2
    or any(DOMAIN_LABEL.fullmatch(label) is None for label in labels)
    or not labels[-1].isalpha()
    or len(labels[-1]) < 2
):
    raise SystemExit(1)

print(email)
