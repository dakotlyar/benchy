#!/bin/sh
# The scratch server's sendmail: PHP's mail() pipes each message here and the tests read them back (tenancy.sh sets sendmail_path to this file).
{ cat; printf '\n=====END OF MESSAGE=====\n'; } >> /tmp/tn-mail.log
