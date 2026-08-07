-- Copyright (C) 2026  Camile   <camilevahviraki@gmail.com>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.
--
-- Cypht's per-user settings, previously one JSON file per user under
-- DOL_DATA_ROOT/cyphtWebmail/users.
--
-- Files were replaced because they scale badly and back up worse: one file
-- per user forever, encrypted with a key held in llx_const, so documents/
-- and the database had to be restored in lockstep or every user's settings
-- became unrecoverable. In a table, key and data land in the same backup,
-- deletion cascades, and renaming a user is a non-event because the row is
-- keyed on the user id rather than the login.
--
-- The blob stays encrypted (Hm_Crypt, CYPHTWEBMAIL_CONFIG_SECRET). Mail
-- credentials belong in llx_cyphtwebmail_account, but a server can sit here
-- transiently between being added in Cypht and being pushed across, so this
-- column must never be readable.


CREATE TABLE llx_cyphtwebmail_userconfig(
	rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity             integer DEFAULT 1 NOT NULL,
	fk_user            integer NOT NULL,
	-- mediumtext, not text: a config with several accounts and the full
	-- default set runs to a few KB today, and text caps at 64KB.
	config             mediumtext,
	date_creation      datetime NOT NULL,
	tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
