Table cache
===========
Stores temporary data

| Field | Description | Type | Null | Key | Default | Extra |
| ----- | ----------- | ---- | ---- | --- | ------- | ----- |
| k | cache key | varbinary(255) | YES | PRI |  |  |    
| v | cached serialized value | mediumtext | NO |  |  |  |    
| expires | datetime of cache expiration | datetime | YES |  | 0001-01-01 00:00:00 |  |    
| updated | datetime of cache insertion | datetime | YES |  | 0001-01-01 00:00:00 |  |    

Return to [database documentation](help/database)
