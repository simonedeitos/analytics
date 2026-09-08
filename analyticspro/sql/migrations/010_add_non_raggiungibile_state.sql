ALTER TABLE properties
    MODIFY COLUMN stato ENUM('non_interessato','interessato','contattato','da_contattare','non_raggiungibile','in_vendita_noi','in_vendita_altri','altro') DEFAULT NULL;
