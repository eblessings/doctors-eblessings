Table attach
===========
file attachments

| Field         | Description                                           | Type               | Null | Key | Default             | Extra          |    
| ------------- | ----------------------------------------------------- | ------------------ | ---- | --- | ------------------- | -------------- |    
| id            | generated index                                       | int unsigned       | NO   | PRI | NULL                | auto_increment |    
| uid           | Owner User id                                         | mediumint unsigned | NO   |     | 0                   |                |    
| hash          | hash                                                  | varchar(64)        | NO   |     |                     |                |    
| filename      | filename of original                                  | varchar(255)       | NO   |     |                     |                |    
| filetype      | mimetype                                              | varchar(64)        | NO   |     |                     |                |    
| filesize      | size in bytes                                         | int unsigned       | NO   |     | 0                   |                |    
| data          | file data                                             | longblob           | NO   |     | NULL                |                |    
| created       | creation time                                         | datetime           | NO   |     | 0001-01-01 00:00:00 |                |    
| edited        | last edit time                                        | datetime           | NO   |     | 0001-01-01 00:00:00 |                |    
| allow_cid     | Access Control - list of allowed contact.id &#039;&lt;19&gt;&lt;78&gt; | mediumtext         | YES  |     | NULL                |                |    
| allow_gid     | Access Control - list of allowed groups               | mediumtext         | YES  |     | NULL                |                |    
| deny_cid      | Access Control - list of denied contact.id            | mediumtext         | YES  |     | NULL                |                |    
| deny_gid      | Access Control - list of denied groups                | mediumtext         | YES  |     | NULL                |                |    
| backend-class | Storage backend class                                 | tinytext           | YES  |     | NULL                |                |    
| backend-ref   | Storage backend data reference                        | text               | YES  |     | NULL                |                |    

Return to [database documentation](help/database)
