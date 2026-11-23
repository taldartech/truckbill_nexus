# PostgreSQL / MySQL Verification

**Date:** 23 November 2026

## PostgreSQL

| Check                        | Result                                          |
| ---------------------------- | ----------------------------------------------- |
| Full Pest                    | **609 passed**, 2 skipped, 3968 assertions      |
| LocalDemoWorkspaceSeederTest | **4 passed** (converted CDF fixture + LR links) |
| Migrations                   | Pass                                            |

## MySQL 8.4

| Check                                | Result                                                                   |
| ------------------------------------ | ------------------------------------------------------------------------ |
| GA #85 migrate step                  | Passed historically                                                      |
| GA #85 Pest                          | Failed on converted enquiry assertion — **fixed in `03164f2`**           |
| Local disposable MySQL re-run on tip | **Not executed** (Docker daemon unavailable; Homebrew datadir untouched) |

Remote MySQL green confirmation requires Product Owner push of tip.
