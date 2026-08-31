# Live network inventory

Source: [wifi_voucher_system_documentation.md](../wifi_voucher_system_documentation.md), [README.md](../README.md). Confirm IPs on the server before changing firewall or CoA targets.

| Role | Equipment | Notes |
|---|---|---|
| Router / WAN gateway | TTCL router `192.168.100.1` | Internet NAT. Not MikroTik. |
| RADIUS / portal host | Ubuntu `192.168.100.100` | FreeRADIUS (1812/1813), PostgreSQL `radius`, PHP portal `:8090` |
| Access point / NAS | TP-Link EAP650 standalone `192.168.100.101` | External web portal + External RADIUS (PAP). NAS ID `EAP650-Portal-01` |
| Backend | PHP 8 + PostgreSQL | Not Django / FastAPI |
| Accounting | FreeRADIUS `radacct` on UDP 1813 | Enabled on AP; interim updates were off at last demo |

**Do not assume MikroTik Hotspot.** Client isolation, VLAN, and tethering inspection belong on the EAP and the TTCL router, not in PHP. CoA Disconnect-Request, if the EAP firmware accepts it, is sent to the **NAS** (AP), typically UDP 3799.
