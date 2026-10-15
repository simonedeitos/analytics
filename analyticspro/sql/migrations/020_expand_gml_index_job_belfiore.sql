-- Liste di codici Belfiore per indicizzare un sottoinsieme del catalogo.
ALTER TABLE gml_index_jobs
    MODIFY COLUMN belfiore TEXT NOT NULL COMMENT 'Codice Belfiore, lista separata da virgole o "ALL"';
