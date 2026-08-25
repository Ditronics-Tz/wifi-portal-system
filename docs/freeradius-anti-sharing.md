# FreeRADIUS anti-sharing snippets

These assume `sql` is already used for `radcheck` / `radreply` / `radacct` against the `radius` database.

## Simultaneous-Use

In the authorize section, after sql:

```
sql
```

Ensure `Simultaneous-Use` is processed (default in many `sites-enabled/default` configs via `sql` + session section). Confirm `sites-enabled/default` has:

```
session {
    sql
}
```

The portal writes `radcheck` `Simultaneous-Use := 1` per voucher username.

## Reply attributes

The portal writes:

- `Session-Timeout`
- `WISPr-Bandwidth-Max-Down` / `WISPr-Bandwidth-Max-Up` (bps) when the package has `bandwidth_mbps`
- `ChilliSpot-Max-Total-Octets` when the package has `data_quota_mb`

TP-Link EAP firmware may ignore WISPr/ChilliSpot attributes. Session-Timeout is the attribute already proven on this AP.

## Accounting

Keep `accounting { sql }` enabled so `radacct` is populated. The admin Sessions page syncs open accounting rows into `voucher_sessions`.
