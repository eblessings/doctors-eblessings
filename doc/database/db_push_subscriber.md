Table push_subscriber
===========
Used for OStatus: Contains feed subscribers

| Field | Description | Type | Null | Key | Default | Extra |
| ----- | ----------- | ---- | ---- | --- | ------- | ----- |
| id | sequential ID | int unsigned | NO | PRI | NULL | auto_increment |    
| uid | User id | mediumint unsigned | NO |  | 0 |  |    
| callback_url |  | varchar(255) | NO |  |  |  |    
| topic |  | varchar(255) | NO |  |  |  |    
| nickname |  | varchar(255) | NO |  |  |  |    
| push | Retrial counter | tinyint | NO |  | 0 |  |    
| last_update | Date of last successful trial | datetime | NO |  | 0001-01-01 00:00:00 |  |    
| next_try | Next retrial date | datetime | NO |  | 0001-01-01 00:00:00 |  |    
| renewed | Date of last subscription renewal | datetime | NO |  | 0001-01-01 00:00:00 |  |    
| secret |  | varchar(255) | NO |  |  |  |    

Return to [database documentation](help/database)
