build_tinydns
==============

This is the module that will enable the ability to extract and build a TinyDNS server configurations from the database. It will output the configuration text that would
normally be located in a data.cdb file.

Install
-------


  * If you have not already, run the following command `echo '/opt/ona' > /etc/onabase`.  This assumes you installed ONA into /opt/ona
  * Ensure you have the following prerequisites installed:
    * A TinyDNS server installation. It is not required to be on the same host as the ONA system.
    * `sendEmail` for notification messages.
    * A functioning dcm.pl install on your DNS server.
  * Download the archive and place it in your $ONABASE/www/local/plugins directory, the directory must be named `build_tinydns`
  * Make the plugin directory owned by your webserver user I.E.: `chown -R www-data /opt/ona/www/local/plugins/build_tinydns`
  * From within the GUI, click _Plugins->Manage Plugins_ while logged in as an admin user
  * Click the install icon for the plugin which should be listed by the plugin name
  * Follow any instructions it prompts you with.
  * Modify the variables at the top of the build_tinydns script to suit your environment.

.... Read the top portion of the build_tinydns script for further details....
