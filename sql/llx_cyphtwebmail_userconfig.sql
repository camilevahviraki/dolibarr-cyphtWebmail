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
-- Cypht's per-user settings and mail accounts, one row per user.
--
-- Keyed on fk_user rather than the login, so renaming a user changes nothing
-- and deletion cascades. The config column is JSON with only the mailbox
-- passwords encrypted (Hm_Crypt, CYPHTWEBMAIL_CONFIG_SECRET), which keeps
-- the rest queryable.


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
