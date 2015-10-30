
insert into sys_config (name, value, description, field_validation_rule, failed_rule_text, editable, deleteable) values ('build_dns_type', 'tinydns', 'DNS build type', '', '', 1, 1) on duplicate key update value='tinydns';
