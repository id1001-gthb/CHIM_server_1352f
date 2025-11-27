SELECT sql_exec2('ALTER TABLE "'||pgc.relname||'" SET (autovacuum_enabled = off, toast.autovacuum_enabled = off) '||';')
 FROM pg_catalog.pg_class pgc
 LEFT JOIN pg_catalog.pg_namespace pgn ON pgn.oid = pgc.relnamespace
 WHERE (pgc.relkind ='r')
 AND (pgn.nspname='public');

