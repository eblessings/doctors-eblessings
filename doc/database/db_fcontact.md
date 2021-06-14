Table fcontact
===========

Diaspora compatible contacts - used in the Diaspora implementation

Fields
------

| Field    | Description   | Type             | Null | Key | Default             | Extra          |
| -------- | ------------- | ---------------- | ---- | --- | ------------------- | -------------- |
| id       | sequential ID | int unsigned     | NO   | PRI | NULL                | auto_increment |
| guid     | unique id     | varchar(255)     | NO   |     |                     |                |
| url      |               | varchar(255)     | NO   |     |                     |                |
| name     |               | varchar(255)     | NO   |     |                     |                |
| photo    |               | varchar(255)     | NO   |     |                     |                |
| request  |               | varchar(255)     | NO   |     |                     |                |
| nick     |               | varchar(255)     | NO   |     |                     |                |
| addr     |               | varchar(255)     | NO   |     |                     |                |
| batch    |               | varchar(255)     | NO   |     |                     |                |
| notify   |               | varchar(255)     | NO   |     |                     |                |
| poll     |               | varchar(255)     | NO   |     |                     |                |
| confirm  |               | varchar(255)     | NO   |     |                     |                |
| priority |               | tinyint unsigned | NO   |     | 0                   |                |
| network  |               | char(4)          | NO   |     |                     |                |
| alias    |               | varchar(255)     | NO   |     |                     |                |
| pubkey   |               | text             | YES  |     | NULL                |                |
| updated  |               | datetime         | NO   |     | 0001-01-01 00:00:00 |                |

Indexes
------------

| Name | Fields |
|------|---------|
| PRIMARY | id |
| addr | addr(32) |
| url | UNIQUE, url(190) |


Return to [database documentation](help/database)
