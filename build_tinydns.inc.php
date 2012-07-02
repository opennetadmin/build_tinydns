<?php

// Lets do some initial install related stuff
if (file_exists(dirname(__FILE__)."/install.php")) {
    printmsg("DEBUG => Found install file for ".basename(dirname(__FILE__))." plugin.", 1);
    include(dirname(__FILE__)."/install.php");
} else {

// Place initial popupwindow content here if this plugin uses one.


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
    $version = '1.02';

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
    txt_notes                Enables building of TXT records from host notes
    ptr_subnets              Enables building of PTR records for subnet addresses
                             Only available when using server option.

\n
EOF
        ));
    }

/*
TODO: MP: deal with creating SOA records properly for each zone as well.. need a loop here.

*/


    $options['txt_notes']   = sanitize_YN($options['txt_notes'], 'N');
    $options['ptr_subnets'] = sanitize_YN($options['ptr_subnets'], 'N');

    $domain_text = '';

    // loop through records and display them
    if ($options['domain']) {
        list($status, $output) = process_domain($options['domain'],$options['txt_notes']);
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
            list($status, $output) = process_domain($sdomain['domain_id'],$options['txt_notes']);
            $text .= $output;
        }

        if ($options['ptr_subnets'] == 'Y') {
            $text .= "# ---------------- START PTR records for all subnets ---------\n";

            list($status, $rows, $subnets) = db_get_records($onadb, 'subnets', 'id > 0');
            foreach ($subnets as $subnet) {
                // FIXME: this probably needs to be fixed so that /32 and maybe /31 subnets dont get processed
                $iprev = ip_mangle($subnet['ip_addr'],'flip');

                $arpatype = ( $subnet['ip_addr'] > '294967295' ? 'ip6' : 'in-addr');

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









function process_domain($domainname="",$txtnotes='') {
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

    list($status, $rows, $records) = db_get_records($onadb, 'dns', array('domain_id' => $domain['id']));

    // print the opening comment with row count
    $text .= "\n\n# ---------------- START DOMAIN {$domain['name']} ---------\n";
    $text .= "# TOTAL RECORDS FOR DOMAIN '{$domain['name']}' (count={$rows})\n";

    // Build the SOA record
    $text .= "Z{$domain['fqdn']}:{$domain['primary_master']}:{$domain['admin_email']}::{$domain['refresh']}:{$domain['retry']}:{$domain['expiry']}:{$domain['minimum']}:{$domain['default_ttl']}::\n\n";

    //http://cr.yp.to/djbdns/tinydns-data.html

    $datawidth = 60;

    // Loop through the record set
    foreach ($records as $dnsrecord) {
        // Dont build records that begin in the future
        // FIXME: MP: find a way to convert a date into TAI64 format so that tinydns can manage its own record activation/deactivation etc.
        if (strtotime($dnsrecord['ebegin']) > time()) continue;
        // Dont build disabled records
        if (strtotime($dnsrecord['ebegin']) < 0) continue;

        // If there are notes, put the comment character in front of it
        if ($dnsrecord['notes']) $dnsrecord['notes'] = '# '.$dnsrecord['notes'];

        // If the ttl is empty then make it truely empty
        if ($dnsrecord['ttl'] == 0) $dnsrecord['ttl'] = '';

        // Dont print a dot unless hostname has a value
        if ($dnsrecord['name']) $dnsrecord['name'] = $dnsrecord['name'].'.';

        // Also, if the records ttl is the same as the domains ttl then dont display it, just to keep it "cleaner"
        if (!strcmp($dnsrecord['ttl'],$domain['default_ttl'])) $dnsrecord['ttl'] = '';


        // Check if this record is primary with a note
        if ($txtnotes == 'Y') {
            list($status, $rows, $hosttxt) = ona_get_record("primary_dns_id = {$dnsrecord['id']} and 'notes' not like ''",'hosts');
            if ($rows) {
                $fqdn = $dnsrecord['name'].$domain['fqdn'];

                // convert the special characters
                //'example.com:colons(\072)\040and\040newlines\040(\015\012)\040need\040to\040be\040escaped.:86400
                // (:) carriage returns (\r) and line feeds. (\n) looks like spaces could be converted too
                // the \r and \n are not working properly when replaced.. for now I'll just blank them out
                $hosttxt['notes'] = preg_replace(array('/:/','/\r/','/\n/'),array("\\\\072"," "," "),$hosttxt['notes']);

                //'fqdn:s:ttl:timestamp:lo..... you need to use octal \nnn codes to include arbitrary bytes inside s; for example, \072 is a colon.
                if ($hosttxt['notes']) $text .= sprintf("'%-{$datawidth}s\n" ,"{$fqdn}:{$hosttxt['notes']}:{$dnsrecord['ttl']}:");
            }
        }

        if ($dnsrecord['type'] == 'NS') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => build_tinydns: Unable to find interface record for NS record!",3);
                $self['error'] = "ERROR => build_tinydns: Unable to find interface record for NS record!";
                //return(array(5, $self['error'] . "\n"));
            }

            // Get the name info that the cname points to
            list($status, $rows, $ns) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            list($status, $rows, $nsdomain) = ona_get_domain_record(array('id' => $dnsrecord['domain_id']), '');

            //&fqdn:ip:x:ttl:timestamp:lo
            $text .= sprintf("&%-{$datawidth}s%s\n" ,"{$nsdomain['fqdn']}::{$ns['fqdn']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }


        if ($dnsrecord['type'] == 'A') {
            $typecode = '+';
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => build_tinydns: Unable to find interface record for A record!",3);
                $self['error'] = "ERROR => build_tinydns: Unable to find interface record for A record!";
                //return(array(5, $self['error'] . "\n"));
                continue;
            }

            $fqdn = $dnsrecord['name'].$domain['fqdn'];

            // Find out if this A record has a PTR as well
            // If it does, go ahead and build a PTR entry.
            // we will never build '=' style tinydns records, only + and ^ together.
            // If we are building at a server level (multiple domains) then we will
            // remove any duplicates that may be added here and in the reverse PTR zone.
            list($status, $ptrrows, $ptr) = ona_get_dns_record(array('dns_id' => $dnsrecord['id'], 'interface_id' => $dnsrecord['interface_id'],'type' => 'PTR'), '');
            if ($ptrrows) {
                    // If the ttl is empty then make it truely empty
                    if ($ptr['ttl'] == 0) $ptr['ttl'] = '';
                    // Also, if the records ttl is the same as the domains ttl then dont display it, just to keep it "cleaner"
                    if (!strcmp($ptr['ttl'],$domain['default_ttl'])) $ptr['ttl'] = '';

                    if ($ptr['notes']) $ptr['notes'] = '# '.$ptr['notes'];

                    list($status, $rows, $int) = ona_get_interface_record(array('id' => $ptr['interface_id']));
                    $arpatype = (strpos($int['ip_addr_text'],':') ? 'ip6' : 'in-addr');
                    $ipflip = ip_mangle($int['ip_addr'],'flip');
                    //^fqdn:p:ttl:timestamp:lo
                    $text .= sprintf("^%-{$datawidth}s%s\n" ,"{$ipflip}.{$arpatype}.arpa:{$fqdn}:{$ptr['ttl']}:",$ptr['notes']);
            }

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

            // Build any PTR records that reference this A record but dont have their own A record
            list($status, $ptrsrows, $ptrs) = db_get_records($onadb, 'dns', "dns_id = {$dnsrecord['id']} and interface_id != {$dnsrecord['interface_id']} and type like 'PTR'", '');
            if ($ptrsrows) {
                foreach ($ptrs as $ptr) {
                    // If the ttl is empty then make it truely empty
                    if ($ptr['ttl'] == 0) $ptr['ttl'] = '';
                    // Also, if the records ttl is the same as the domains ttl then dont display it, just to keep it "cleaner"
                    if (!strcmp($ptr['ttl'],$domain['default_ttl'])) $ptr['ttl'] = '';
                    if ($ptr['notes']) $ptr['notes'] = '# '.$ptr['notes'];

                    list($status, $rows, $int) = ona_get_interface_record(array('id' => $ptr['interface_id']));
                    $arpatype = (strpos($int['ip_addr_text'],':') ? 'ip6' : 'in-addr');
                    $ipflip = ip_mangle($int['ip_addr'],'flip');
                    //^fqdn:p:ttl:timestamp:lo
                    $text .= sprintf("^%-{$datawidth}s%s\n" ,"{$ipflip}.{$arpatype}.arpa:{$fqdn}:{$ptr['ttl']}:",$ptr['notes']);
                }
            }

        }

        if ($dnsrecord['type'] == 'CNAME') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => build_tinydns: Unable to find interface record for CNAME record!",3);
                $self['error'] = "ERROR => build_tinydns: Unable to find interface record for CNAME record!";
                //return(array(5, $self['error'] . "\n"));
                continue;
            }

            // Get the name info that the cname points to
            list($status, $rows, $cname) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            $fqdn = $dnsrecord['name'].$domain['fqdn'];
            // Cfqdn:p:ttl:timestamp:lo
            $text .= sprintf("C%-{$datawidth}s%s\n" ,"{$fqdn}:{$cname['name']}.{$cname['domain_fqdn']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }



        if ($dnsrecord['type'] == 'MX') {
            // Find the interface record
//             list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
//             if ($status or !$rows) {
//                 printmsg("ERROR => Unable to find interface record!",3);
//                 $self['error'] = "ERROR => Unable to find interface record!";
//                 //return(array(5, $self['error'] . "\n"));
//             }


// I removed {$interface['ip_addr_text']} from the entry so that the IP would not be in the list.. it is not needed as
// MX records require them to point to an A record that should have already been created in the file.

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
            $fqdn = $dnsrecord['name'].$domain['fqdn'];

            // convert the special characters
            //'example.com:colons(\072)\040and\040newlines\040(\015\012)\040need\040to\040be\040escaped.:86400
            // (:) carriage returns (\r) and line feeds. (\n) looks like spaces could be converted too
            // the \r and \n are not tested but should work.
            $dnsrecord['txt'] = preg_replace(array('/:/','/\r/','/\n/'),array("\\\\072"," "," "),$dnsrecord['txt']);

            //'fqdn:s:ttl:timestamp:lo..... you need to use octal \nnn codes to include arbitrary bytes inside s; for example, \072 is a colon.
            if ($dnsrecord['txt']) $text .= sprintf("'%-{$datawidth}s%s\n" ,"{$fqdn}:{$dnsrecord['txt']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }


        if ($dnsrecord['type'] == 'PTR') {
            // Find the interface record
            list($status, $rows, $interface) = ona_get_interface_record(array('id' => $dnsrecord['interface_id']));
            if ($status or !$rows) {
                printmsg("ERROR => build_tinydns: Unable to find interface record for PTR record!",3);
                $self['error'] = "ERROR => Ubuild_tinydns: nable to find interface record for PTR record!";
                //return(array(5, $self['error'] . "\n"));
                continue;
            }

            // Get the name info that the record points to
            list($status, $rows, $ptr) = ona_get_dns_record(array('id' => $dnsrecord['dns_id']), '');

            // If the ttl is empty then make it truely empty
            if ($dnsrecord['ttl'] == 0) $dnsrecord['ttl'] = '';
            // Also, if the records ttl is the same as the domains ttl then dont display it, just to keep it "cleaner"
            if (!strcmp($ptr['ttl'],$domain['default_ttl'])) $ptr['ttl'] = '';
            if ($dnsrecord['notes']) $dnsrecord['notes'] = '# '.$dnsrecord['notes'];

            $fqdn = $dnsrecord['name'].$domain['fqdn'];
            // flip the IP
            $iprev = ip_mangle($interface['ip_addr'],'flip');

            $arpatype = (strpos($interface['ip_addr_text'],':') ? 'ip6' : 'in-addr');

            //^fqdn:p:ttl:timestamp:lo  --- PTR record format
            $text .= sprintf("^%-{$datawidth}s%s\n" ,"{$iprev}.{$arpatype}.arpa:{$ptr['fqdn']}:{$dnsrecord['ttl']}:",$dnsrecord['notes']);
        }

    }

    // Need to do SRV records like this so users dont have to patch tinydns.
    // found some great examples of all this at: http://www.anders.com/projects/sysadmin/djbdnsRecordBuilder/buildRecord.txt
    // :sip.tcp.example.com:33:\000\001\000\002\023\304\003pbx\007example\003com\000


    $text .= "# ---------------- END DOMAIN {$domain['name']} ---------\n";

    // MP: FIXME.  need to figure out how to deal with PTR records that have no A record with them.. currently busted as a whole.

    // Return the zone file
    return(array(0, $text));

}











?>
