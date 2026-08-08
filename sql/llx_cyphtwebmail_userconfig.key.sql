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


-- Unique, not just indexed: exactly one config row per user per entity is the
-- invariant the whole design rests on. Cypht writes this from its own process
-- with no transaction around it, so two concurrent requests could otherwise
-- insert two rows and the user would start losing settings at random
-- depending on which one a later read happened to pick up.
ALTER TABLE llx_cyphtwebmail_userconfig ADD UNIQUE INDEX uk_cyphtwebmail_userconfig (entity, fk_user);
ALTER TABLE llx_cyphtwebmail_userconfig ADD CONSTRAINT fk_cyphtwebmail_userconfig_fk_user FOREIGN KEY (fk_user) REFERENCES llx_user (rowid) ON DELETE CASCADE;
