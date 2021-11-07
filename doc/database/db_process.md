Table process
===========

Currently running system processes

Fields
------

| Field    | Description                        | Type          | Null | Key | Default             | Extra |
| -------- | ---------------------------------- | ------------- | ---- | --- | ------------------- | ----- |
| pid      | The process ID of the current node | int unsigned  | NO   | PRI | NULL                |       |
| hostname | The hostname of the current node   | varchar(32)   | NO   | PRI | NULL                |       |
| command  |                                    | varbinary(32) | NO   |     |                     |       |
| created  |                                    | datetime      | NO   |     | 0001-01-01 00:00:00 |       |

Indexes
------------

| Name    | Fields        |
| ------- | ------------- |
| PRIMARY | pid, hostname |
| command | command       |


Return to [database documentation](help/database)
