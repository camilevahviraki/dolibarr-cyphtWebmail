## Dolibarr Contacts

This module set provides read-only access to the third parties and contacts
of the Dolibarr installation Cypht is embedded in. Addresses are fetched over
HTTP from the module's bridge endpoint rather than read from the database, so
permission checks, entity scoping and schema knowledge all stay on the
Dolibarr side.

Sites must enable the contacts module set, and the module set must appear
after "contacts" in CYPHT_MODULES since it attaches to its load_contacts
handler.
