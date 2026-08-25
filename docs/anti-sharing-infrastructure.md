# Anti-sharing on this stack (TP-Link EAP650 + FreeRADIUS)

Application changes (sessions, Simultaneous-Use, CoA) are not enough. Apply these on the AP and router.

## Client isolation (required)

On EAP650 (Omada controller or standalone Wireless Control):

1. Open the SSID used for vouchers.
2. Enable **AP/Client Isolation** (also called Station Isolation).
3. Confirm clients cannot ping another client on the same SSID.

This blocks station-to-station traffic. It does **not** stop a phone hotspot (downstream NAT).

## RADIUS accounting

Keep Accounting enabled toward `192.168.100.100:1813`. Turn **interim updates** on (e.g. 300s) if you want the admin Sessions page to stay current. The portal reads `radacct` rows with `acctstoptime IS NULL`.

## Firewall / segmentation

On the TTCL gateway and AP:

- Customer WLAN must not reach `192.168.100.100` except portal `:8090`, DNS, DHCP, and RADIUS from the AP itself.
- Do not expose PostgreSQL, SSH, or AP management from the guest SSID.
- Prefer a guest VLAN if the router supports it (management vs customers).

## Tethering (best-effort)

The EAP does not expose per-user TTL/connection tracking like MikroTik Hotspot. Detection in this project is:

- One session / one MAC lock in the portal
- `Simultaneous-Use := 1` in FreeRADIUS
- Multiple live `radacct` rows or MACs for the same username → security event

USB tethering and phone hotspots behind one authenticated MAC will often look like a single client. For stronger inspection you would need a gateway that can classify that traffic (MikroTik or similar) **in front of** the EAP, not more PHP.

## CoA / Disconnect

`radius_disconnect()` sends a Disconnect-Request to `RADIUS_NAS_IP:RADIUS_COA_PORT` (default AP `192.168.100.133:3799`). Confirm the EAP firmware accepts RFC 5176. If it does not, “End voucher” still expires the code in the database; the AP may keep the station until Session-Timeout.
