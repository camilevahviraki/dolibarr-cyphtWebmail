<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		SuperAdmin
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   cypht-webmail     Module cyphtWebmail
 *  \brief      cyphtWebmail module descriptor.
 *
 *  \file       htdocs/cyphtWebmail/core/modules/modcyphtWebmail.class.php
 *  \ingroup    cyphtWebmail
 *  \brief      Description and activation file for module cyphtWebmail
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 *  Description and activation class for module cyphtWebmail
 */
class modcyphtWebmail extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 500000; // TODO Go on page https://wiki.dolibarr.org/index.php/List_of_modules_id to reserve an id number for your module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'cyphtwebmail';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "interface";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModulecyphtWebmailName' not found (cyphtWebmail is name of module).
		$this->name = strtolower(preg_replace('/^mod/i', '', get_class($this)));

		// DESCRIPTION_FLAG
		// Module description, used if translation string 'ModulecyphtWebmailDesc' not found (cyphtWebmail is name of module).
		$this->description = "cyphtWebmailDescription";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "cyphtWebmailDescription";

		// Enables the module for all entities (Multicompany)
		// Can be enabled / disabled only from the main company with superadmin account
		// $this->core_enabled = 1;

		// Author
		$this->editor_name = 'CamiluxTest';
		$this->editor_url = '';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@cyphtWebmail'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '1.0';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where cyphtWebmail is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		// Syntax is 'fa-<key>_<prefix>_<color>_<size>', parsed in img_picto()
		// (core/lib/functions.lib.php). Deliberately not Dolibarr's built-in
		// 'email' picto: modMailing, modEmailCollector and modNotification all
		// already use that one, and two of them are linked from this module's
		// own left menu, so sharing it would make three different things look
		// identical. Green ties it to Cypht's own mark.
		$this->picto = 'fa-envelope-open-text_fas_#1d9e75';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		// If your module use the parameter "core_enabled" and you would like to activate the parts in all entities (Multicompany):
		// 'xxxxxx' => array(
		//		'data' => 1 or '/cyphtWebmail/xxx/cyphtWebmail.xxx.php',
		//		'entity' => '0'
		//	),
		// 'hooks' => array(
		//		'data' => array(
		//			'hookcontext1',
		//			'hookcontext2',
		//		)
		//		'entity' => '0'
		//	)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			// core/triggers/interface_99_modcyphtWebmail_CyphtWebmailTriggers
			// deletes a user's Cypht settings file on USER_DELETE. Nothing
			// else knows that file exists.
			'triggers' => 1,
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 0,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/cyphtWebmail/css/cyphtWebmail.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/cyphtWebmail/js/cyphtWebmail.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => array(
				// 'hookcontext1',
				// 'hookcontext2',
			),
			/* END MODULEBUILDER HOOKSCONTEXTS */
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/cyphtWebmail/temp","/cyphtWebmail/subdir");
		$this->dirs = array("/cyphtWebmail/temp");

		// Config pages. Put here list of php page, stored into cyphtWebmail/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@cyphtwebmail");

		// Dependencies
		// A condition to hide module
		$this->hidden = getDolGlobalInt('MODULE_cyphtWebmail_DISABLED'); // A condition to disable module;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("cyphtwebmail@cyphtwebmail");

		// Prerequisites
		$this->phpmin = array(7, 2); // Minimum version of PHP required by module
		// $this->phpmax = array(8, 0); // Maximum version of PHP required by module
		$this->need_dolibarr_version = array(19, -3); // Minimum version of Dolibarr required by module
		// $this->max_dolibarr_version = array(19, -3); // Maximum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array(); 		// Warning to show when we activate a module. Example: array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); 	// Warning to show when we activate a module if another module is on. Example: array('modOtherModule' => array('always'=>'text')) or array('always' => array('FR'=>'textfr','MX'=>'textmx'...))
		//$this->automatic_activation = array('FR'=>'cyphtWebmailWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = false;			// If true, can't be disabled. Value true is reserved for core modules. Not allowed for external modules.

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('cyphtWebmail_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('cyphtWebmail_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array();

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isModEnabled("cyphtwebmail")) {
			$conf->cyphtwebmail = new stdClass();
			$conf->cyphtwebmail->enabled = 0;
		}

		// Array to add new pages in new tabs
		/* BEGIN MODULEBUILDER TABS */
		// Don't forget to deactivate/reactivate your module to test your changes
		$this->tabs = array();
		/* END MODULEBUILDER TABS */
		// Example:
		// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data' => 'objecttype:+tabname1:Title1:mylangfile@cyphtWebmail:$user->hasRight('cyphtWebmail', 'myobject', 'read'):/cyphtWebmail/mynewtab1.php?id=__ID__');
		// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data' => 'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@cyphtWebmail:$user->hasRight('othermodule', 'otherobject', 'read'):/cyphtWebmail/mynewtab2.php?id=__ID__',
		// To remove an existing tab identified by code tabname
		// $this->tabs[] = array('data' => 'objecttype:-tabname:NU:conditiontoremove');
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'delivery'         to add a tab in delivery view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'supplier_invoice' to add a tab in supplier invoice view
		// 'member'           to add a tab in foundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'supplier_order'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'supplier_payment' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view


		// Dictionaries
		/* Example:
		 $this->dictionaries=array(
		 'langs' => 'cyphtWebmail@cyphtWebmail',
		 // List of tables we want to see into dictionary editor
		 'tabname' => array("table1", "table2", "table3"),
		 // Label of tables
		 'tablib' => array("Table1", "Table2", "Table3"),
		 // Request to select fields
		 'tabsql' => array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table3 as f'),
		 // Sort order
		 'tabsqlsort' => array("label ASC", "label ASC", "label ASC"),
		 // List of fields (result of select to show dictionary)
		 'tabfield' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields to edit a record)
		 'tabfieldvalue' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields for insert)
		 'tabfieldinsert' => array("code,label", "code,label", "code,label"),
		 // Name of columns with primary key (try to always name it 'rowid')
		 'tabrowid' => array("rowid", "rowid", "rowid"),
		 // Condition to show each dictionary
		 'tabcond' => array(isModEnabled('cyphtWebmail'), isModEnabled('cyphtWebmail'), isModEnabled('cyphtWebmail')),
		 // Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
		 'tabhelp' => array(array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		 );
		 */
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array();
		/* END MODULEBUILDER DICTIONARIES */

		// Boxes/Widgets
		// Add here list of php file(s) stored in cyphtWebmail/core/boxes that contains a class to show a widget.
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'cyphtWebmailwidget1.php@cyphtWebmail',
			//      'note' => 'Widget provided by cyphtWebmail',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);
		/* END MODULEBUILDER WIDGETS */

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array(
			//  0 => array(
			//      'label' => 'MyJob label',
			//      'jobtype' => 'method',
			//      'class' => '/cyphtWebmail/class/myobject.class.php',
			//      'objectname' => 'MyObject',
			//      'method' => 'doScheduledJob',
			//      'parameters' => '',
			//      'comment' => 'Comment',
			//      'frequency' => 2,
			//      'unitfrequency' => 3600,
			//      'status' => 0,
			//      'test' => 'isModEnabled("cyphtWebmail")',
			//      'priority' => 50,
			//  ),
		);
		/* END MODULEBUILDER CRON */
		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'isModEnabled("cyphtWebmail")', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'isModEnabled("cyphtWebmail")', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		/* BEGIN MODULEBUILDER PERMISSIONS */
		/*
		$o = 1;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 1); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Read objects of cyphtWebmail'; // Permission label
		$this->rights[$r][4] = 'myobject';
		$this->rights[$r][5] = 'read'; // In php code, permission will be checked by test if ($user->hasRight('cyphtWebmail', 'myobject', 'read'))
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 2); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Create/Update objects of cyphtWebmail'; // Permission label
		$this->rights[$r][4] = 'myobject';
		$this->rights[$r][5] = 'write'; // In php code, permission will be checked by test if ($user->hasRight('cyphtWebmail', 'myobject', 'write'))
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf("%02d", ($o * 10) + 3); // Permission id (must not be already used)
		$this->rights[$r][1] = 'Delete objects of cyphtWebmail'; // Permission label
		$this->rights[$r][4] = 'myobject';
		$this->rights[$r][5] = 'delete'; // In php code, permission will be checked by test if ($user->hasRight('cyphtWebmail', 'myobject', 'delete'))
		$r++;
		*/
		/* END MODULEBUILDER PERMISSIONS */


		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */
		$this->menu[$r++] = array(
			'fk_menu' => '', // Will be stored into mainmenu + leftmenu. Use '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'top', // This is a Top menu entry
			'titre' => 'ModulecyphtWebmailName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => '',
			'url' => '/cyphtWebmail/cyphtWebmailindex.php',
			'langs' => 'cyphtwebmail@cyphtwebmail', // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('cyphtwebmail')", // Define condition to show or hide menu entry. Use "isModEnabled('cyphtWebmail')" if entry must be visible if module is enabled (those quote marks are importants).
			'perms' => '1', // Use 'perms'=>'$user->hasRight("cyphtWebmail", "myobject", "read")' if you want your menu with a permission rules
			'target' => '',
			'user' => 2, // 0=Menu for internal users, 1=external users, 2=both
		);
		/* END MODULEBUILDER TOPMENU */

		/* BEGIN LEFTMENU CYPHTWEBMAIL */
		// Carries only what Cypht cannot: Dolibarr work queues, the mail
		// automation Dolibarr owns, and module setup. Cypht's own sidebar
		// already covers mail, contacts, servers and filters.
		//
		// 'fk_mainmenu=cyphtwebmail' must match the lower case 'mainmenu'
		// value on the top entry, or the entries never appear.

		// --- Back to the mail client -------------------------------------
		// Every other entry in this column navigates away from the webmail
		// page, and the only route back would otherwise be the top menu. This
		// is not a duplicate of Cypht's own sidebar: Cypht has no way to
		// return you to the Dolibarr page that embeds it.
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuWebmail',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_index',
			'url' => '/cyphtWebmail/cyphtWebmailindex.php',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('cyphtwebmail')",
			'perms' => '1',
			'target' => '',
			'user' => 2,
		);

		// --- Work queues -------------------------------------------------
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuOpenTickets',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_tickets',
			'url' => '/ticket/list.php?search_fk_statut[]=openall',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('ticket')",
			'perms' => '$user->hasRight("ticket", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuOverdueInvoices',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_invoices',
			// search_option=late is read at compta/facture/list.php:201 and
			// forces search_status to unpaid; same pair core's own menu uses.
			'url' => '/compta/facture/list.php?search_option=late&search_status=1',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			// The module key is 'invoice'; the permission is still 'facture'.
			'enabled' => "isModEnabled('invoice')",
			'perms' => '$user->hasRight("facture", "lire")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuTodayAgenda',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_agenda',
			'url' => '/comm/action/index.php',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('agenda')",
			'perms' => '$user->hasRight("agenda", "myactions", "read")',
			'target' => '',
			'user' => 2,
		);

		// --- Mail automation Dolibarr owns -------------------------------
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuEmailCollector',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_collector',
			'url' => '/admin/emailcollector_list.php',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('emailcollector')",
			// EmailCollector declares no rights of its own: it is a setup area.
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuEmailTemplates',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_templates',
			'url' => '/admin/mails_templates.php',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => '1',
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuMassEmailing',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_mailing',
			'url' => '/comm/mailing/list.php',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('mailing')",
			'perms' => '$user->hasRight("mailing", "lire")',
			'target' => '',
			'user' => 2,
		);

		// --- Setup -------------------------------------------------------
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=cyphtwebmail',
			'type' => 'left',
			'titre' => 'CyphtMenuModuleSetup',
			'mainmenu' => 'cyphtwebmail',
			'leftmenu' => 'cyphtwebmail_setup',
			'url' => '/cyphtWebmail/admin/setup.php',
			'langs' => 'cyphtwebmail@cyphtwebmail',
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('cyphtwebmail')",
			'perms' => '$user->admin',
			'target' => '',
			'user' => 0,
		);
		/* END LEFTMENU CYPHTWEBMAIL */

		/* BEGIN MODULEBUILDER LEFTMENU MYOBJECT */
		/*
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=cyphtWebmail',      // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',                          // This is a Left menu entry
			'titre' => 'MyObject',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'cyphtWebmail',
			'leftmenu' => 'myobject',
			'url' => '/cyphtWebmail/cyphtWebmailindex.php',
			'langs' => 'cyphtWebmail@cyphtWebmail',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('cyphtWebmail')", // Define condition to show or hide menu entry. Use isModEnabled("cyphtWebmail") if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("cyphtWebmail", "myobject", "read")',
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'MyObject'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=cyphtWebmail,fk_leftmenu=myobject',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'New_MyObject',
			'mainmenu' => 'cyphtWebmail',
			'leftmenu' => 'cyphtWebmail_myobject_new',
			'url' => '/cyphtWebmail/myobject_card.php?action=create',
			'langs' => 'cyphtWebmail@cyphtWebmail',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('cyphtWebmail')", // Define condition to show or hide menu entry. Use isModEnabled("cyphtWebmail") if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
			'perms' => '$user->hasRight("cyphtWebmail", "myobject", "write")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'MyObject'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=cyphtWebmail,fk_leftmenu=myobject',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'List_MyObject',
			'mainmenu' => 'cyphtWebmail',
			'leftmenu' => 'cyphtWebmail_myobject_list',
			'url' => '/cyphtWebmail/myobject_list.php',
			'langs' => 'cyphtWebmail@cyphtWebmail',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('cyphtWebmail')", // Define condition to show or hide menu entry. Use isModEnabled("cyphtWebmail") if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("cyphtWebmail", "myobject", "read")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'MyObject'
		);
		*/
		/* END MODULEBUILDER LEFTMENU MYOBJECT */


		// Exports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER EXPORT MYOBJECT */
		/*
		$langs->load("cyphtWebmail@cyphtWebmail");
		$this->export_code[$r] = $this->rights_class.'_'.$r;
		$this->export_label[$r] = 'MyObjectLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->export_icon[$r] = $this->picto;
		// Define $this->export_fields_array, $this->export_TypeFields_array and $this->export_entities_array
		$keyforclass = 'MyObject'; $keyforclassfile='/cyphtWebmail/class/myobject.class.php'; $keyforelement='myobject@cyphtWebmail';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		//$this->export_fields_array[$r]['t.fieldtoadd']='FieldToAdd'; $this->export_TypeFields_array[$r]['t.fieldtoadd']='Text';
		//unset($this->export_fields_array[$r]['t.fieldtoremove']);
		//$keyforclass = 'MyObjectLine'; $keyforclassfile='/cyphtWebmail/class/myobject.class.php'; $keyforelement='myobjectline@cyphtWebmail'; $keyforalias='tl';
		//include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		$keyforselect='myobject'; $keyforaliasextra='extra'; $keyforelement='myobject@cyphtWebmail';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$keyforselect='myobjectline'; $keyforaliasextra='extraline'; $keyforelement='myobjectline@cyphtWebmail';
		//include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$this->export_dependencies_array[$r] = array('myobjectline' => array('tl.rowid','tl.ref')); // To force to activate one or several fields if we select some fields that need same (like to select a unique key if we ask a field of a child to avoid the DISTINCT to discard them, or for computed field than need several other fields)
		//$this->export_special_array[$r] = array('t.field' => '...');
		//$this->export_examplevalues_array[$r] = array('t.field' => 'Example');
		//$this->export_help_array[$r] = array('t.field' => 'FieldDescHelp');
		$this->export_sql_start[$r]='SELECT DISTINCT ';
		$this->export_sql_end[$r]  =' FROM '.$this->db->prefix().'cyphtWebmail_myobject as t';
		//$this->export_sql_end[$r]  .=' LEFT JOIN '.$this->db->prefix().'cyphtWebmail_myobject_line as tl ON tl.fk_myobject = t.rowid';
		$this->export_sql_end[$r] .=' WHERE 1 = 1';
		$this->export_sql_end[$r] .=' AND t.entity IN ('.getEntity('myobject').')';
		$r++; */
		/* END MODULEBUILDER EXPORT MYOBJECT */

		// Imports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/*
		$langs->load("cyphtWebmail@cyphtWebmail");
		$this->import_code[$r] = $this->rights_class.'_'.$r;
		$this->import_label[$r] = 'MyObjectLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r] = $this->picto;
		$this->import_tables_array[$r] = array('t' => $this->db->prefix().'cyphtWebmail_myobject', 'extra' => $this->db->prefix().'cyphtWebmail_myobject_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'MyObject'; $keyforclassfile='/cyphtWebmail/class/myobject.class.php'; $keyforelement='myobject@cyphtWebmail';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='myobject'; $keyforaliasextra='extra'; $keyforelement='myobject@cyphtWebmail';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.$this->db->prefix().'cyphtWebmail_myobject');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(!getDolGlobalString('cyphtWebmail_MYOBJECT_ADDON') ? 'mod_myobject_standard' : getDolGlobalString('cyphtWebmail_MYOBJECT_ADDON')),
				'path'=>"/core/modules/cyphtWebmail/".(!getDolGlobalString('cyphtWebmail_MYOBJECT_ADDON') ? 'mod_myobject_standard' : getDolGlobalString('cyphtWebmail_MYOBJECT_ADDON')).'.php',
				'classobject'=>'MyObject',
				'pathobject'=>'/cyphtWebmail/class/myobject.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$this->import_run_sql_after_array[$r] = array();
		$r++; */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		//$result = $this->_load_tables('/install/mysql/', 'cyphtWebmail');
		$result = $this->_load_tables('/cyphtWebmail/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Create extrafields during init
		//include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		//$extrafields = new ExtraFields($this->db);
		//$result0=$extrafields->addExtraField('cyphtWebmail_separator1', "Separator 1", 'separator', 1,  0, 'thirdparty',   0, 0, '', array('options'=>array(1=>1)), 1, '', 1, 0, '', '', 'cyphtWebmail@cyphtWebmail', 'isModEnabled("cyphtWebmail")');
		//$result1=$extrafields->addExtraField('cyphtWebmail_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', -1, 0, '', '', 'cyphtWebmail@cyphtWebmail', 'isModEnabled("cyphtWebmail")');
		//$result2=$extrafields->addExtraField('cyphtWebmail_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', -1, 0, '', '', 'cyphtWebmail@cyphtWebmail', 'isModEnabled("cyphtWebmail")');
		//$result3=$extrafields->addExtraField('cyphtWebmail_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', -1, 0, '', '', 'cyphtWebmail@cyphtWebmail', 'isModEnabled("cyphtWebmail")');
		//$result4=$extrafields->addExtraField('cyphtWebmail_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', -1, 0, '', '', 'cyphtWebmail@cyphtWebmail', 'isModEnabled("cyphtWebmail")');
		//$result5=$extrafields->addExtraField('cyphtWebmail_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', -1, 0, '', '', 'cyphtWebmail@cyphtWebmail', 'isModEnabled("cyphtWebmail")');

		// Permissions
		$this->remove($options);

		$sql = array();

		// Document templates
		$moduledir = dol_sanitizeFileName('cyphtWebmail');
		$myTmpObjects = array();
		$myTmpObjects['MyObject'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);

		foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
			if ($myTmpObjectArray['includerefgeneration']) {
				$src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_myobjects.odt';
				$dirodt = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/doctemplates/'.$moduledir;
				$dest = $dirodt.'/template_myobjects.odt';

				if (file_exists($src) && !file_exists($dest)) {
					require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
					dol_mkdir($dirodt);
					$result = dol_copy($src, $dest, '0', 0);
					if ($result < 0) {
						$langs->load("errors");
						$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
						return 0;
					}
				}

				$sql = array_merge($sql, array(
					"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'standard_".strtolower($myTmpObjectKey)."' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('standard_".strtolower($myTmpObjectKey)."', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")",
					"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'generic_".strtolower($myTmpObjectKey)."_odt' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('generic_".strtolower($myTmpObjectKey)."_odt', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")"
				));
			}
		}

		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
