<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for ".basename(dirname(__FILE__))." plugin.", 1);
    include(dirname(__FILE__)."/install.php");
} else {

// Place initial popupwindow content here if this plugin uses one.


}





// Octal character count
function characterCount($line) {
  return( sprintf("\%'.03o", strlen($line)) );
}

// Octal escape a number
function escnum($number) {
  $highNumber = 0;
  if ( $number - 256 >= 0 ) {
    $highNumber = (int)( $number / 256 );
    $number = $number - ( $highNumber * 256 );
  }
  $out = sprintf("\%'.03o", $highNumber);
  $out = $out.sprintf("\%'.03o", $number);

  return( $out );
}



// Octal escape non alpha characters
function esctext($text) {
  $esc = '';
  foreach(str_split($text) as $char) {
    if (!preg_match("/[a-zA-Z0-9]/", $char)) {
    #if (!preg_match("/[\r\n\t: \/]/", $char)) {
      $esc = $esc.sprintf("\%'.03o",ord($char));
    } else {
      $esc = $esc.$char;
    }
  }
  return $esc;
}





// Make sure we have necessary functions & DB connectivity
require_once($conf['inc_functions_db']);


///////////////////////////////////////////////////////////////////////
//  Function: build_tinydns_conf (string $options='')
//
//  Input Options:
//    $options = key=value pairs of options for this function.
//               multiple sets of key=value pairs should be separated
//               by an "&" symbol.
//
//  Output:
//    Returns a two part list:
//      1. The exit status of the function (0 on success, non-zero on error)
//      2. A textual message for display on the console or web interface.
//
//  Example: list($status, $result) = build_tinydns_conf('domain=test');
//
//  Exit codes:
//    0  :: No error
//    1  :: Help text printed - Insufficient or invalid input received
//    2  :: No such host
//
//
//
///////////////////////////////////////////////////////////////////////
function build_tinydns_conf($options="") {

    // The important globals
    global $conf, $self, $onadb;

    // Version - UPDATE on every edit!
    $version = '1.06';

    printmsg("DEBUG => build_tinydns_conf({$options}) called", 3);

    // Parse incoming options string to an array
    $options = parse_options($options);

    // Return the usage summary if we need to
    if ($options['help'] or (!$options['domain'] and !$options['server'])) {
        // NOTE: Help message lines should not exceed 80 characters for proper display on a console
        $self['error'] = 'ERROR => Insufficient parameters';
        return(array(1,
<<<EOF

build_tinydns_conf-v{$version}
Builds a tinydns config file for a server from the database

  Synopsis: build_tinydns_conf [KEY=VALUE] ...

  Required:
    domain=DOMAIN or ID      Build config file for specified domain
    OR
    server=NAME or ID        Build config for specified server

  Optional:
    ptr_subnets              Enables building of PTR records for subnet addresses
                             Only available when using server option.

\n
EOF
        ));
    }

/*
TODO: MP: deal with creating SOA records properly for each zone as well.. need a loop here.

*/


    $options['ptr_subnets'] = sanitize_YN($options['ptr_subnets'], 'N');

    $domain_text = '';

    // loop through records and display them
    if ($options['domain']) {
        list($status, $output) = process_domain($options['domain']);
        $text = "# TinyDNS config file for domain '{$options['domain']}'\n";
        $text .= $output;
    }

    if ($options['server']) {
        list($status, $rows, $shost) = ona_find_host($options['server']);
        printmsg("DEBUG => build_tinydns_conf() server record: {$domain['server']}", 3);
        if (!$shost['id']) {
            printmsg("DEBUG => Unknown server record: {$options['server']}",3);
            $self['error'] = "ERROR => Unknown server record: {$options['server']}";
            return(array(3, $self['error'] . "\n"));
        }

        $text = "\n# TinyDNS config file for server '{$options['server']}'\n";
        $text .= "#You should add any manual changes needed to the 'data.ona.header' file that gets appended at the top of this file during rebuilds.\n";

        // Get all the domains for this server, sort so the in-addr.arpa stuff is at the bottom (usually)
        list($status, $rows, $records) = db_get_records($onadb, 'dns_server_domains a, domains d', "a.host_id = {$shost['id']} AND a.domain_id = d.id", 'd.name desc');

        foreach ($records as $sdomain) {
            list($status, $output) = process_domain($sdomain['domain_id']);
            $text .= $output;
        }

        if ($options['ptr_subnets'] == 'Y') {
            $text .= "# ---------------- START PTR records for all subnets ---------\n";

            list($status, $rows, $subnets) = db_get_records($onadb, 'subnets', 'id > 0');
            foreach ($subnets as $subnet) {
                // FIXME: this probably needs to be fixed so that /32 and maybe /31 subnets dont get processed
                $iprev = ip_mangle($subnet['ip_addr'],'flip');

                $arpatype = ( $subnet['ip_addr'] > '4294967295' ? 'ip6' : 'in-addr');

                //^fqdn:p:ttl:timestamp:lo  --- PTR record format
                $text .= sprintf("^%s\n" ,"{$iprev}.{$arpatype}.arpa:{$subnet['name']}::");
            }

            $text .= "# ---------------- END PTR records for all subnets ---------\n";
        }

        $text .= "#Server build done.\n";

        // Turn $text into an array of lines and remove duplicates
        // This is done so we dont get duplicate records like PTR records from reverse domains and hosts etc.
        $text = explode("\n", $text);
        $text = array_unique($text);
        $text = implode("\n", $text);
    }



    // Return the zone file
    return(array(0, $text));

}









function process_domain($domainname='',$output_type='tinydns') {
    global $onadb;
    $text = '';

    if (is_numeric($domainname)) {
        list($status, $rows, $domain) = ona_get_domain_record(array('id' => $domainname));
        if (!$rows) {
            printmsg("DEBUG => Unknown domain record: {$domainname}",3);
            $self['error'] = "ERROR => Unknown domain record: {$domainname}";
            return(array(2, $self['error'] . "\n"));
        }
    } else {
        // Get the domain information
        list($status, $rows, $domain) = ona_find_domain($domainname);
        printmsg("DEBUG => build_tinydns_conf() Domain record: {$domain['zone']}", 3);
        if (!$domain['id']) {
            printmsg("DEBUG => Unknown domain record: {$domainname}",3);
            $self['error'] = "ERROR => Unknown domain record: {$domainname}";
            return(array(2, $self['error'] . "\n"));
        }
    }

    $domain['name'] = ona_build_domain_name($domain['id']);

    // print the opening comment with row count
    $text .= "\n\n# ---------------- START DOMAIN {$domain['name']} ---------\n";

    // Build the SOA record
    $text .= "Z{$domain['fqdn']}:{$domain['primary_master']}:{$domain['admin_email']}::{$domain['refresh']}:{$domain['retry']}:{$domain['expiry']}:{$domain['minimum']}:{$domain['default_ttl']}::\n\n";

    //http://cr.yp.to/djbdns/tinydns-data.html

    $datawidth = 60;

    // Lets gather the data about the records in the domain.  This uses a lot of SQL fun to try and be efficient
    // TODO: test this better against non MYSQL based backends

    $query="
select
  dns_views.name as view,
  cast(case
    when type = 'ptr' AND ip_addr < 4294967296 then trim(trailing concat('.',domains.name) from concat_ws('.', ip_addr % 256, (ip_addr >> 8) % 256, (ip_addr >> 16) % 256, (ip_addr >> 24) % 256, 'in-addr.arpa'))
    when type = 'ptr' AND ip_addr > 4294967296 then trim(trailing concat('.',domains.name) from concat_ws('.', ip_addr % 256, (ip_addr >> 8) % 256, (ip_addr >> 16) % 256, (ip_addr >> 24) % 256, 'ip6.arpa'))
    when dns.name = '' then '@'
    else concat(dns.name,'.',domains.name)
    end as char) as fqdn,
  dns.name,
  case when ttl > 0 then ttl else default_ttl end as ttl,
  cast(case
    when type = 'A' AND ip_addr < 4294967296 then 'A'
    when type = 'A' AND ip_addr > 4294967296 then 'AAAA'
    else type
    end as char) as type,
  case when type = 'mx' then mx_preference else '' end as mx_preference,
  case when type = 'srv' then srv_pri else '' end as srv_pri,
  case when type = 'srv' then srv_weight else '' end as srv_weight,
  case when type = 'srv' then srv_port else '' end as srv_port,
  cast(case
    when type = 'txt' then txt
    when type = 'srv' then concat_ws(' ',
      (select concat(dns2.name, '.', domains.name, '.') from
      dns as dns2 inner join domains on domains.id = dns2.domain_id where dns.dns_id = dns2.id))
    when type in ('ptr', 'cname', 'mx', 'ns') then (select concat(dns2.name,'.',domains.name,'.') from
      dns as dns2 inner join domains on domains.id = dns2.domain_id where dns.dns_id = dns2.id)
    when type = 'A' then inet_ntoa(interfaces.ip_addr)
    when type = 'AAAA' then inet_ntoa(interfaces.ip_addr)
    end as char) as data,
  dns.notes,
  dns.interface_id,
  dns.dns_id,
  interfaces.ip_addr,
  domains.name as domain_fqdn,
  dns.ebegin

from dns
  inner join domains on dns.domain_id = domains.id
  inner join dns_views on dns.dns_view_id = dns_views.id
  left  join interfaces on interfaces.id = dns.interface_id

where (dns.ebegin > 0 and now() >= dns.ebegin)
and dns.domain_id = {$domain['id']}
    ";

    // exectue the query
    $rs = $onadb->Execute($query);
    if ($rs === false or (!$rs->RecordCount())) {
        $self['error'] = 'ERROR => build_tinydns(): SQL query failed: ' . $onadb->ErrorMsg();
        printmsg($self['error'], 0);
    }
    $rows = $rs->RecordCount();

    // Loop through the record set
    while ($dnsrecord = $rs->FetchRow()) {
        // Dont build records that begin in the future
        // FIXME: MP: find a way to convert a date into TAI64 format so that tinydns can manage its own record activation/deactivation etc.
        if (strtotime($dnsrecord['ebegin']) > time()) continue;
        // Dont build disabled records
        if (strtotime($dnsrecord['ebegin']) < 0) continue;

        // If there are notes, put the comment character in front of it
        if ($dnsrecord['notes']) $dnsrecord['notes'] = '# '.str_replace("\n"," ",$dnsrecord['notes']);

        // Dont print a dot unless hostname has a value
        if ($dnsrecord['name']) $dnsrecord['name'] = $dnsrecord['name'].'.';
        $fqdn = $dnsrecord['name'].$domain['fqdn'];

        if ($dnsrecord['type'] == 'NS') {
            // Get the name info that the cname points to
            list($status, $rows, $ns) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            list($status, $rows, $nsdomain) = ona_get_domain_record(array('id' => $dnsrecord['domain_id']), '');

            //&fqdn:ip:x:ttl:timestamp:lo
            $text .= sprintf("&%-{$datawidth}s%s\n" ,"{$dnsrecord['domain_fqdn']}::{$ns['fqdn']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }


        if ($dnsrecord['type'] == 'A') {
            $typecode = '+';

            // Get the ip text
            $interface['ip_addr_text'] = ip_mangle($dnsrecord['ip_addr'],'dotted');

            // See if this is a ipv6 record and convert the data
            if (strpos($interface['ip_addr_text'],':')) {
                $ipoct = '';
                foreach (str_split(str_replace(':','',ip_mangle($interface['ip_addr'],'ipv6')),2) as $v6hex)  {
                    $ipoct .= sprintf("\%03s",base_convert($v6hex,16,8));
                }
                $interface['ip_addr_text'] = '28:'.$ipoct;
            }

            // +fqdn:ip:ttl:timestamp:lo
            $text .= sprintf("%s%-{$datawidth}s%s\n" ,$typecode,"{$fqdn}:{$interface['ip_addr_text']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }

        if ($dnsrecord['type'] == 'CNAME') {
            // Get the name info that the cname points to
            list($status, $rows, $cname) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            // Cfqdn:p:ttl:timestamp:lo
            $text .= sprintf("C%-{$datawidth}s%s\n" ,"{$fqdn}:{$cname['name']}.{$cname['domain_fqdn']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }



        if ($dnsrecord['type'] == 'MX') {
            // Get the name info that the record points to
            list($status, $rows, $mx) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            if ($dnsrecord['name']) {
                $name = $dnsrecord['name'].$domain['name'];
            }
            else {
                $name = $domain['name'];
            }

            // @fqdn:ip:x:dist:ttl:timestamp:lo
            $text .= sprintf("@%-{$datawidth}s%s\n" ,"{$name}::{$mx['fqdn']}:{$dnsrecord['mx_preference']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }




        if ($dnsrecord['type'] == 'TXT') {
            // convert the special characters
            //'example.com:colons(\072)\040and\040newlines\040(\015\012)\040need\040to\040be\040escaped.:86400
            // (:) carriage returns (\r) and line feeds. (\n) looks like spaces could be converted too
            // the \r and \n are not tested but should work.
            $dnsrecord['txt'] = preg_replace(array('/:/','/\r/','/\n/'),array("\\\\072"," "," "),$dnsrecord['data']);

            //'fqdn:s:ttl:timestamp:lo..... you need to use octal \nnn codes to include arbitrary bytes inside s; for example, \072 is a colon.
            $text .= sprintf("'%-{$datawidth}s%s\n" ,"{$fqdn}:{$dnsrecord['txt']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }


        if ($dnsrecord['type'] == 'PTR') {
            // Get the name info that the record points to
            list($status, $rows, $ptr) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            // If the ttl is empty then make it truely empty
            if ($dnsrecord['ttl'] == 0) $dnsrecord['ttl'] = '';
            // Also, if the records ttl is the same as the domains ttl then dont display it, just to keep it "cleaner"
            if (!strcmp($ptr['ttl'],$domain['default_ttl'])) $ptr['ttl'] = '';
            if ($dnsrecord['notes']) $dnsrecord['notes'] = '# '.$dnsrecord['notes'];

            // flip the IP
            $iprev = ip_mangle($dnsrecord['ip_addr'],'flip');

            $arpatype = (strpos($dnsrecord['ip_addr_text'],':') ? 'ip6' : 'in-addr');

            //^fqdn:p:ttl:timestamp:lo  --- PTR record format
            $text .= sprintf("^%-{$datawidth}s%s\n" ,"{$iprev}.{$arpatype}.arpa:{$ptr['fqdn']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }

        // Process SRV records
        // found some great examples of all this at: http://www.anders.com/projects/sysadmin/djbdnsRecordBuilder/buildRecord.txt
        if ($dnsrecord['type'] == 'SRV') {
            // Get the name info that the SRV points to
            list($status, $rows, $srv) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            $tar = '';
            $chunks = explode(".", $srv['name'].".".$srv['domain_fqdn']);
            foreach ($chunks as $chunk) {
              $tar = $tar . characterCount( $chunk ) . $chunk;
            }

            // :sip.tcp.example.com:33:\000\001\000\002\023\304\003pbx\007example\003com\000:3600
            $text .= sprintf(":%-{$datawidth}s%s\n" ,"{$fqdn}:33:".escnum($dnsrecord['srv_pri']).escnum($dnsrecord['srv_weight']).escnum($dnsrecord['srv_port']).$tar."\\000:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }

    }

    $text .= "# ---------------- END DOMAIN {$domain['name']} ---------\n";

    // MP: FIXME.  need to figure out how to deal with PTR records that have no A record with them.. currently busted as a whole.????

    // Return the zone file
    return(array(0, $text));

}











?>
