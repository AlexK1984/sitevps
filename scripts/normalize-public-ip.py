#!/usr/bin/env python3

import ipaddress
import sys

try:
    address = ipaddress.ip_address(sys.argv[1].strip())
except (IndexError, ValueError):
    raise SystemExit(1)

if isinstance(address, ipaddress.IPv6Address) and address.ipv4_mapped is not None:
    address = address.ipv4_mapped

if not address.is_global:
    raise SystemExit(1)

print(address)
