<?php
/* Copyright (C) 2004-2017 Laurent Destailleur  <eldy@users.sourceforge.net>
 * Copyright (C) 2020 SuperAdmin
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    cfdibulkload/admin/setup.php
 * \ingroup cfdibulkload
 * \brief   CfdiBulkLoad setup page.
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) $res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"] . "/main.inc.php";
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1)) . "/main.inc.php")) $res = @include substr($tmp, 0, ($i + 1)) . "/main.inc.php";
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php")) $res = @include dirname(substr($tmp, 0, ($i + 1))) . "/main.inc.php";
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

global $langs, $user;

// Libraries
require_once DOL_DOCUMENT_ROOT . "/core/lib/admin.lib.php";
require_once '../lib/cfdibulkload.lib.php';
//require_once "../class/myclass.class.php";
require_once DOL_DOCUMENT_ROOT . '/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/files.lib.php';

// Translations
$langs->loadLangs(array("admin", "cfdibulkload@cfdibulkload"));

// Access control
if (!$user->admin) accessforbidden();

// Parameters
$action = GETPOST('action', 'alpha');
$backtopage = GETPOST('backtopage', 'alpha');

$value = GETPOST('value', 'alpha');

$arrayofparameters = array(
	'CFDIBULKLOAD_PASSWORD' => array('css' => '', 'enabled' => 1, 'input_type' => 'password'),
);

$error = 0;
$setupnotempty = 0;

$upload_dir = DOL_DATA_ROOT . '/cfdibulkload/admin/cert';

// Local variables
$encryption_method = $conf->global->CFDIBULKLOAD_ENCRYPTION_METHOD;
$encryption_key = $conf->file->instance_unique_id;
$encryption_iv = hex2bin($conf->global->CFDIBULKLOAD_ENCRYPTION_IV);

/*
 * Actions
 */

if ((float) DOL_VERSION >= 6) {

	// Encrypt value of password fields
	if ($action == 'update' && is_array($arrayofparameters)) {

		// Check for parameters marked as password
		foreach ($arrayofparameters as $parameter => $parameter_value) {
			if (GETPOSTISSET($parameter) && $parameter_value['input_type'] == 'password') {
				$password = openssl_encrypt(GETPOST($parameter), $encryption_method, $encryption_key, 0, $encryption_iv);
				$_POST[$parameter] = $password;
			}
		}
	}

	include DOL_DOCUMENT_ROOT . '/core/actions_setmoduleoptions.inc.php';
}

if ($action == 'updateMask') {
	$maskconstorder = GETPOST('maskconstorder', 'alpha');
	$maskorder = GETPOST('maskorder', 'alpha');

	if ($maskconstorder) $res = dolibarr_set_const($db, $maskconstorder, $maskorder, 'chaine', 0, '', $conf->entity);

	if (!$res > 0) $error++;

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
} elseif ($action == 'specimen') {
	$modele = GETPOST('module', 'alpha');
	$tmpobjectkey = GETPOST('object');

	$tmpobject = new $tmpobjectkey($db);
	$tmpobject->initAsSpecimen();

	// Search template files
	$file = '';
	$classname = '';
	$filefound = 0;
	$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);
	foreach ($dirmodels as $reldir) {
		$file = dol_buildpath($reldir . "core/modules/cfdibulkload/doc/pdf_" . $modele . "_" . strtolower($tmpobjectkey) . ".modules.php", 0);
		if (file_exists($file)) {
			$filefound = 1;
			$classname = "pdf_" . $modele;
			break;
		}
	}

	if ($filefound) {
		require_once $file;

		$module = new $classname($db);

		if ($module->write_file($tmpobject, $langs) > 0) {
			header("Location: " . DOL_URL_ROOT . "/document.php?modulepart=" . strtolower($tmpobjectkey) . "&file=SPECIMEN.pdf");
			return;
		} else {
			setEventMessages($module->error, null, 'errors');
			dol_syslog($module->error, LOG_ERR);
		}
	} else {
		setEventMessages($langs->trans("ErrorModuleNotFound"), null, 'errors');
		dol_syslog($langs->trans("ErrorModuleNotFound"), LOG_ERR);
	}
}

// Activate a model
elseif ($action == 'set') {
	$ret = addDocumentModel($value, $type, $label, $scandir);
} elseif ($action == 'del') {
	$tmpobjectkey = GETPOST('object');

	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$constforval = strtoupper($tmpobjectkey) . '_ADDON_PDF';
		if ($conf->global->$constforval == "$value") dolibarr_del_const($db, $constforval, $conf->entity);
	}
}

// Set default model
elseif ($action == 'setdoc') {
	$tmpobjectkey = GETPOST('object');
	$constforval = strtoupper($tmpobjectkey) . '_ADDON_PDF';
	if (dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity)) {
		// The constant that was read before the new set
		// We therefore requires a variable to have a coherent view
		$conf->global->$constforval = $value;
	}

	// On active le modele
	$ret = delDocumentModel($value, $type);
	if ($ret > 0) {
		$ret = addDocumentModel($value, $type, $label, $scandir);
	}
} elseif ($action == 'setmod') {
	// TODO Check if numbering module chosen can be activated
	// by calling method canBeActivated
	$tmpobjectkey = GETPOST('object');
	$constforval = 'CFDIBULKLOAD_' . strtoupper($tmpobjectkey) . "_ADDON";
	dolibarr_set_const($db, $constforval, $value, 'chaine', 0, '', $conf->entity);
}

// Delete files
elseif ($action == 'delete') {
	$file = DOL_DATA_ROOT . '/cfdibulkload/' . GETPOST('urlfile', 'alpha');
	dol_delete_file($file);
}

// Upload certificate files
elseif (GETPOST('sendit')) {
	dol_add_file_process($upload_dir, 1, 0, 'userfile');
}

/*
 * View
 */

$form = new Form($db);
$formFile = new FormFile($db);

$dirmodels = array_merge(array('/'), (array) $conf->modules_parts['models']);

$page_name = "CfdiBulkLoadSetup";
llxHeader('', $langs->trans($page_name));

// Subheader
$linkback = '<a href="' . ($backtopage ? $backtopage : DOL_URL_ROOT . '/admin/modules.php?restore_lastsearch_values=1') . '">' . $langs->trans("BackToModuleList") . '</a>';

print load_fiche_titre($langs->trans($page_name), $linkback, 'object_cfdibulkload@cfdibulkload');

// Configuration header
$head = cfdibulkloadAdminPrepareHead();
dol_fiche_head($head, 'settings', '', -1, "cfdibulkload@cfdibulkload");

// Setup page goes here
echo '<span class="opacitymedium">' . $langs->trans("CfdiBulkLoadSetupPage") . '</span><br><br>';


if ($action == 'edit') {
	print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
	print '<input type="hidden" name="token" value="' . newToken() . '">';
	print '<input type="hidden" name="action" value="update">';

	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans("Parameter") . '</td><td>' . $langs->trans("Value") . '</td></tr>';

	foreach ($arrayofparameters as $key => $val) {
		$setupnotempty++;
		$tooltiphelp = (($langs->trans($key . 'Tooltip') != $key . 'Tooltip') ? $langs->trans($key . 'Tooltip') : '');
		$input_type = $val['input_type'];
		$input_type_attr = (!empty($input_type)) ? 'type="' . $input_type . '"' : '';

		echo '<tr class="oddeven">';
		echo '<td>' . $form->textwithpicto($langs->trans($key), $tooltiphelp) . '</td>';
		echo '<td><input ' . $input_type_attr . ' name="' . $key .  '" class="flat ' . (empty($val['css']) ? 'minwidth200' : $val['css']) . '" value="' . (($input_type == 'password') ? '' : $conf->global->$key) . '"></td>';
		echo '</tr>';
	}
	print '</table>';

	print '<br><div class="center">';
	print '<input class="button" type="submit" value="' . $langs->trans("Save") . '">';
	print '</div>';

	print '</form>';
	print '<br>';
} else {
	if (!empty($arrayofparameters)) {
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre"><td class="titlefield">' . $langs->trans("Parameter") . '</td><td>' . $langs->trans("Value") . '</td></tr>';

		foreach ($arrayofparameters as $key => $val) {
			$setupnotempty++;
			$tooltiphelp = (($langs->trans($key . 'Tooltip') != $key . 'Tooltip') ? $langs->trans($key . 'Tooltip') : '');
			$input_type = $val['input_type'];

			if ($input_type == 'password') {
				$decrypted_password = openssl_decrypt($conf->global->$key, $encryption_method, $encryption_key, 0, $encryption_iv);
				$field_value = str_repeat('*', strlen($decrypted_password));
			} else {
				$field_value = $conf->global->$key;
			}

			print '<tr class="oddeven"><td>';
			print $form->textwithpicto($langs->trans($key), $tooltiphelp);
			print '</td><td>' . $field_value . '</td></tr>';
		}

		print '</table>';

		print '<div class="tabsAction">';
		print '<a class="butAction" href="' . $_SERVER["PHP_SELF"] . '?action=edit">' . $langs->trans("Modify") . '</a>';
		print '</div>';

		// Show list of files
		$filearray = dol_dir_list($upload_dir, 'all', 0, '', null, 'name', SORT_ASC, 1);
		$formFile->list_of_documents($filearray, null, $module, '', 0, 'admin/cert/', 1, 0, '', 0, $langs->trans('ElectronicSignatureFiles'), '', 0, 0, $upload_dir);

		// Dont show upload form if .key & .cer files were already uploaded
		if (count($filearray) < 2) {

			// Accept just the file extensions that are not already present on the server
			$valid_extensions = ['.cer', '.key'];
			foreach ($filearray as $file) {
				foreach ($valid_extensions as $index => $extension) {
					if (strpos($file['name'], $extension) !== false) {
						array_splice($valid_extensions, $index, 1);
					};
				}
			}


			// File upload form
			$formFile->form_attach_new_file(
				$_SERVER["PHP_SELF"],
				$langs->trans('UPLOAD_FIEL_FILES'),
				0,
				0,
				1,
				50,
				'',
				'',
				1,
				'',
				0,
				'formuserfile',
				implode(',', $valid_extensions)
			);
		}
	} else {
		print '<br>' . $langs->trans("NothingToSetup");
	}
}


$moduledir = 'cfdibulkload';
$myTmpObjects = array();
$myTmpObjects['MyObject'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);


foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
	if ($myTmpObjectKey == 'MyObject') continue;
	if ($myTmpObjectArray['includerefgeneration']) {
		/*
		 * Orders Numbering model
		 */
		$setupnotempty++;

		print load_fiche_titre($langs->trans("NumberingModules", $myTmpObjectKey), '', '');

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>' . $langs->trans("Name") . '</td>';
		print '<td>' . $langs->trans("Description") . '</td>';
		print '<td class="nowrap">' . $langs->trans("Example") . '</td>';
		print '<td class="center" width="60">' . $langs->trans("Status") . '</td>';
		print '<td class="center" width="16">' . $langs->trans("ShortInfo") . '</td>';
		print '</tr>' . "\n";

		clearstatcache();

		foreach ($dirmodels as $reldir) {
			$dir = dol_buildpath($reldir . "core/modules/" . $moduledir);

			if (is_dir($dir)) {
				$handle = opendir($dir);
				if (is_resource($handle)) {
					while (($file = readdir($handle)) !== false) {
						if (strpos($file, 'mod_' . strtolower($myTmpObjectKey) . '_') === 0 && substr($file, dol_strlen($file) - 3, 3) == 'php') {
							$file = substr($file, 0, dol_strlen($file) - 4);

							require_once $dir . '/' . $file . '.php';

							$module = new $file($db);

							// Show modules according to features level
							if ($module->version == 'development' && $conf->global->MAIN_FEATURES_LEVEL < 2) continue;
							if ($module->version == 'experimental' && $conf->global->MAIN_FEATURES_LEVEL < 1) continue;

							if ($module->isEnabled()) {
								dol_include_once('/' . $moduledir . '/class/' . strtolower($myTmpObjectKey) . '.class.php');

								print '<tr class="oddeven"><td>' . $module->name . "</td><td>\n";
								print $module->info();
								print '</td>';

								// Show example of numbering model
								print '<td class="nowrap">';
								$tmp = $module->getExample();
								if (preg_match('/^Error/', $tmp)) print '<div class="error">' . $langs->trans($tmp) . '</div>';
								elseif ($tmp == 'NotConfigured') print $langs->trans($tmp);
								else print $tmp;
								print '</td>' . "\n";

								print '<td class="center">';
								$constforvar = 'CFDIBULKLOAD_' . strtoupper($myTmpObjectKey) . '_ADDON';
								if ($conf->global->$constforvar == $file) {
									print img_picto($langs->trans("Activated"), 'switch_on');
								} else {
									print '<a href="' . $_SERVER["PHP_SELF"] . '?action=setmod&object=' . strtolower($myTmpObjectKey) . '&value=' . $file . '">';
									print img_picto($langs->trans("Disabled"), 'switch_off');
									print '</a>';
								}
								print '</td>';

								$mytmpinstance = new $myTmpObjectKey($db);
								$mytmpinstance->initAsSpecimen();

								// Info
								$htmltooltip = '';
								$htmltooltip .= '' . $langs->trans("Version") . ': <b>' . $module->getVersion() . '</b><br>';

								$nextval = $module->getNextValue($mytmpinstance);
								if ("$nextval" != $langs->trans("NotAvailable")) {  // Keep " on nextval
									$htmltooltip .= '' . $langs->trans("NextValue") . ': ';
									if ($nextval) {
										if (preg_match('/^Error/', $nextval) || $nextval == 'NotConfigured')
											$nextval = $langs->trans($nextval);
										$htmltooltip .= $nextval . '<br>';
									} else {
										$htmltooltip .= $langs->trans($module->error) . '<br>';
									}
								}

								print '<td class="center">';
								print $form->textwithpicto('', $htmltooltip, 1, 0);
								print '</td>';

								print "</tr>\n";
							}
						}
					}
					closedir($handle);
				}
			}
		}
		print "</table><br>\n";
	}

	if ($myTmpObjectArray['includedocgeneration']) {
		/*
		 * Document templates generators
		 */
		$setupnotempty++;
		$type = strtolower($myTmpObjectKey);

		print load_fiche_titre($langs->trans("DocumentModules", $myTmpObjectKey), '', '');

		// Load array def with activated templates
		$def = array();
		$sql = "SELECT nom";
		$sql .= " FROM " . MAIN_DB_PREFIX . "document_model";
		$sql .= " WHERE type = '" . $type . "'";
		$sql .= " AND entity = " . $conf->entity;
		$resql = $db->query($sql);
		if ($resql) {
			$i = 0;
			$num_rows = $db->num_rows($resql);
			while ($i < $num_rows) {
				$array = $db->fetch_array($resql);
				array_push($def, $array[0]);
				$i++;
			}
		} else {
			dol_print_error($db);
		}

		print "<table class=\"noborder\" width=\"100%\">\n";
		print "<tr class=\"liste_titre\">\n";
		print '<td>' . $langs->trans("Name") . '</td>';
		print '<td>' . $langs->trans("Description") . '</td>';
		print '<td class="center" width="60">' . $langs->trans("Status") . "</td>\n";
		print '<td class="center" width="60">' . $langs->trans("Default") . "</td>\n";
		print '<td class="center" width="38">' . $langs->trans("ShortInfo") . '</td>';
		print '<td class="center" width="38">' . $langs->trans("Preview") . '</td>';
		print "</tr>\n";

		clearstatcache();

		foreach ($dirmodels as $reldir) {
			foreach (array('', '/doc') as $valdir) {
				$realpath = $reldir . "core/modules/" . $moduledir . $valdir;
				$dir = dol_buildpath($realpath);

				if (is_dir($dir)) {
					$handle = opendir($dir);
					if (is_resource($handle)) {
						while (($file = readdir($handle)) !== false) {
							$filelist[] = $file;
						}
						closedir($handle);
						arsort($filelist);

						foreach ($filelist as $file) {
							if (preg_match('/\.modules\.php$/i', $file) && preg_match('/^(pdf_|doc_)/', $file)) {
								if (file_exists($dir . '/' . $file)) {
									$name = substr($file, 4, dol_strlen($file) - 16);
									$classname = substr($file, 0, dol_strlen($file) - 12);

									require_once $dir . '/' . $file;
									$module = new $classname($db);

									$modulequalified = 1;
									if ($module->version == 'development' && $conf->global->MAIN_FEATURES_LEVEL < 2) $modulequalified = 0;
									if ($module->version == 'experimental' && $conf->global->MAIN_FEATURES_LEVEL < 1) $modulequalified = 0;

									if ($modulequalified) {
										print '<tr class="oddeven"><td width="100">';
										print(empty($module->name) ? $name : $module->name);
										print "</td><td>\n";
										if (method_exists($module, 'info')) print $module->info($langs);
										else print $module->description;
										print '</td>';

										// Active
										if (in_array($name, $def)) {
											print '<td class="center">' . "\n";
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=del&value=' . $name . '">';
											print img_picto($langs->trans("Enabled"), 'switch_on');
											print '</a>';
											print '</td>';
										} else {
											print '<td class="center">' . "\n";
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=set&value=' . $name . '&amp;scan_dir=' . $module->scandir . '&amp;label=' . urlencode($module->name) . '">' . img_picto($langs->trans("Disabled"), 'switch_off') . '</a>';
											print "</td>";
										}

										// Default
										print '<td class="center">';
										$constforvar = 'CFDIBULKLOAD_' . strtoupper($myTmpObjectKey) . '_ADDON';
										if ($conf->global->$constforvar == $name) {
											print img_picto($langs->trans("Default"), 'on');
										} else {
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=setdoc&value=' . $name . '&amp;scan_dir=' . $module->scandir . '&amp;label=' . urlencode($module->name) . '" alt="' . $langs->trans("Default") . '">' . img_picto($langs->trans("Disabled"), 'off') . '</a>';
										}
										print '</td>';

										// Info
										$htmltooltip = '' . $langs->trans("Name") . ': ' . $module->name;
										$htmltooltip .= '<br>' . $langs->trans("Type") . ': ' . ($module->type ? $module->type : $langs->trans("Unknown"));
										if ($module->type == 'pdf') {
											$htmltooltip .= '<br>' . $langs->trans("Width") . '/' . $langs->trans("Height") . ': ' . $module->page_largeur . '/' . $module->page_hauteur;
										}
										$htmltooltip .= '<br>' . $langs->trans("Path") . ': ' . preg_replace('/^\//', '', $realpath) . '/' . $file;

										$htmltooltip .= '<br><br><u>' . $langs->trans("FeaturesSupported") . ':</u>';
										$htmltooltip .= '<br>' . $langs->trans("Logo") . ': ' . yn($module->option_logo, 1, 1);
										$htmltooltip .= '<br>' . $langs->trans("MultiLanguage") . ': ' . yn($module->option_multilang, 1, 1);

										print '<td class="center">';
										print $form->textwithpicto('', $htmltooltip, 1, 0);
										print '</td>';

										// Preview
										print '<td class="center">';
										if ($module->type == 'pdf') {
											print '<a href="' . $_SERVER["PHP_SELF"] . '?action=specimen&module=' . $name . '&object=' . $myTmpObjectKey . '">' . img_object($langs->trans("Preview"), 'generic') . '</a>';
										} else {
											print img_object($langs->trans("PreviewNotAvailable"), 'generic');
										}
										print '</td>';

										print "</tr>\n";
									}
								}
							}
						}
					}
				}
			}
		}

		print '</table>';
	}
}

if (empty($setupnotempty)) {
	print '<br>' . $langs->trans("NothingToSetup");
}

// Page end
dol_fiche_end();

llxFooter();
$db->close();
