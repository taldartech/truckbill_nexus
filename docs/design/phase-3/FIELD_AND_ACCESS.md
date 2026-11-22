# Phase 3 Fields and Access (Draft)

## Party (Rules §6)

| Role                         | Required highlights                                                    |
| ---------------------------- | ---------------------------------------------------------------------- |
| Customer/Consignor/Consignee | Name*, Contact Number*, State*, City*                                  |
| Transporter                  | Name*, Contact Number*, State*, City*, PAN*, PAN Status*, Declaration* |
| Driver                       | Mobile*; Permanent requires License Number                             |
| General Vendor               | Name*, Contact Person*, Contact Number*                                |
| Pump Vendor                  | Name*, Contact Person*, Contact Number*                                |

## Access

| Actor         | Party                          | Documents                  | Subscription draft            | Billing UI          |
| ------------- | ------------------------------ | -------------------------- | ----------------------------- | ------------------- |
| Owner         | Full (company active)          | Per file policy            | Yes                           | Yes                 |
| Member Admin  | PM create/edit/delete          | PM + tenant                | No                            | No                  |
| Member Viewer | View only                      | View if permitted          | No                            | No                  |
| Internal      | No Customer masters by default | Blocked for customer files | SA/admin contract tools later | Issuer config later |

Company legal/tax master fields remain Pending (Phase 2 exception).
