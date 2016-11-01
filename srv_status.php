<?php
/*
** Zabbix
** Copyright (C) 2001-2016 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


$period_start=0;
$period_end=0;


require_once dirname(__FILE__).'/include/config.inc.php';
require_once dirname(__FILE__).'/include/triggers.inc.php';
require_once dirname(__FILE__).'/include/services.inc.php';

$page['title'] = _('IT services');
$page['file'] = 'srv_status.php';


define('ZBX_PAGE_DO_REFRESH', 1);

require_once dirname(__FILE__).'/include/page_header.php';

$periods = [
	'today' => _('Today'),
	'week' => _('This week'),
	'month' => _('This month'),
	'year' => _('This year'),
	24 => _('Last 24 hours'),
	24 * 7 => _('Last 7 days'),
	24 * 30 => _('Last 30 days'),
	24 * DAY_IN_YEAR => _('Last 365 days'),
	'personalizado'=>_('Custom')
];

$_aDi = getRequest('di','01/01/2016 00:00');
$_aDf = getRequest('df','01/01/2016 23:59');

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = [
	'serviceid' =>	[T_ZBX_INT, O_OPT, P_SYS|P_NZERO, DB_ID,	null],
	'showgraph' =>	[T_ZBX_INT, O_OPT, P_SYS,	IN('1'),		'isset({serviceid})'],
	'period' =>		[T_ZBX_STR, O_OPT, P_SYS,	IN('"'.implode('","', array_keys($periods)).'"'),	null],
	'fullscreen' => [T_ZBX_INT, O_OPT, P_SYS,	IN('0,1'),		null]
];
check_fields($fields);

if (isset($_REQUEST['serviceid']) && isset($_REQUEST['showgraph'])) {
	$service = API::Service()->get([
		'output' => ['serviceid'],
		'serviceids' => getRequest('serviceid')
	]);
	$service = reset($service);

	if ($service) {
		$table = (new CDiv())
			->addClass(ZBX_STYLE_TABLE_FORMS_CONTAINER)
			->addClass(ZBX_STYLE_CENTER)
			->addItem(new CImg('chart5.php?serviceid='.$service['serviceid'].url_param('path')))
			->show();
	}
	else {
		access_deny();
	}
}
else {
	$period = getRequest('period', 7 * 24);
	$period_end = time();

	switch ($period) {
                case 'personalizado':
                        $_n = array();
                        preg_match_all("/(\d{2})\/(\d{2})\/(\d{4}) ?(\d{2})?:?(\d{2})?/",$_aDi,$_n);
                        $_d = isset($_n[1][0])?(int)$_n[1][0]:1;
                        $_m = isset($_n[2][0])?(int)$_n[2][0]:1;
                        $_y = isset($_n[3][0])?(int)$_n[3][0]:2016;
                        $_h = isset($_n[4][0])?(int)$_n[4][0]:0;
                        $_i = isset($_n[5][0])?(int)$_n[5][0]:0;
                        $_o = array();
                        preg_match_all("/(\d{2})\/(\d{2})\/(\d{4}) ?(\d{2})?:?(\d{2})?/",$_aDf,$_o);
                        $__d = isset($_o[1][0])?(int)$_o[1][0]:1;
                        $__m = isset($_o[2][0])?(int)$_o[2][0]:1;
                        $__y = isset($_o[3][0])?(int)$_o[3][0]:2016;
                        $__h = isset($_o[4][0])?(int)$_o[4][0]:0;
                        $__i = isset($_o[5][0])?(int)$_o[5][0]:0;

                        $period_start = mktime($_h,$_i,0,$_m,$_d,$_y);
                        $period_end = mktime($__h,$__i,0,$__m,$__d,$__y);
                        break;

		case 'today':
			$period_start = mktime(0, 0, 0, date('n'), date('j'), date('Y'));
			break;
		case 'week':
			$period_start = strtotime('last sunday');
			break;
		case 'month':
			$period_start = mktime(0, 0, 0, date('n'), 1, date('Y'));
			break;
		case 'year':
			$period_start = mktime(0, 0, 0, 1, 1, date('Y'));
			break;
		case 24:
		case 24 * 7:
		case 24 * 30:
		case 24 * DAY_IN_YEAR:
			$period_start = $period_end - ($period * 3600);
			break;
	}

	// fetch services
	$services = API::Service()->get([
		'output' => ['name', 'serviceid', 'showsla', 'goodsla', 'algorithm'],
		'selectParent' => ['serviceid'],
		'selectDependencies' => ['servicedownid', 'soft', 'linkid'],
		'selectTrigger' => ['description', 'triggerid', 'expression'],
		'preservekeys' => true,
		'sortfield' => 'sortorder',
		'sortorder' => ZBX_SORT_UP
	]);

	// expand trigger descriptions
	$triggers = zbx_objectValues(
		array_filter($services, function($service) { return (bool) $service['trigger']; }), 'trigger'
	);
	$triggers = CMacrosResolverHelper::resolveTriggerNames(zbx_toHash($triggers, 'triggerid'));

	foreach ($services as &$service) {
		if ($service['trigger']) {
			$service['trigger'] = $triggers[$service['trigger']['triggerid']];
		}
	}
	unset($service);

	// fetch sla
	$slaData = API::Service()->getSla([
		'intervals' => [[
			'from' => $period_start,
			'to' => $period_end
		]]
	]);
	// expand problem trigger descriptions
	foreach ($slaData as &$serviceSla) {
		foreach ($serviceSla['problems'] as &$problemTrigger) {
			$problemTrigger['description'] = $triggers[$problemTrigger['triggerid']]['description'];
		}
		unset($problemTrigger);
	}
	unset($serviceSla);

	$treeData = [];
#	createServiceMonitoringTree($services, $slaData, $period, $treeData);
        createServiceMonitoringTree($period_start, $period_end, $services, $slaData, $period, $treeData);

	$tree = new CServiceTree('service_status_tree',
		$treeData,
		[
			'caption' => _('Service'),
			'status' => _('Status'),
			'reason' => _('Reason'),
			'sla' => (new CColHeader(_('Problem time')))->setColSpan(2),
			'sla2' => null,
			'sla3' => nbsp(_('SLA').' / '._('Acceptable SLA'))
		]
	);

	if ($tree) {
		// creates form for choosing a preset interval
		$r_form = (new CForm('get'))
			->setAttribute('name', 'period_choice')
			->addVar('fullscreen', $_REQUEST['fullscreen']);
		
                $_di = new CInput('text','di',$_aDi);
                //$_di->addAction('onkeyup','javascript:maskDateTime(this);');

                $_df = new CInput('text','df',$_aDf);
                //$_df->addAction('onkeyup','javascript:maskDateTime(this);');

                $period_combo = new CComboBox('period', $period, 'javascript: submit();');
                foreach ($periods as $key => $val) {
                        $period_combo->addItem($key, $val);
                }

		// controls
                //$r_form->addItem([_('Period').SPACE.' :',' de ',$_di,' a ',$_df,' ', $period_combo])
		//->addItem(get_icon('fullscreen', ['fullscreen' => $_REQUEST['fullscreen']]));
		$r_form->addItem((new CList())
			//->addItem([_('Period'), SPACE, $period_combo])
			->addItem([_('Period').SPACE.' :',' from: ',$_di,' to: ',$_df,' ', $period_combo])
			->addItem(get_icon('fullscreen', ['fullscreen' => $_REQUEST['fullscreen']]))
		);
 
		$srv_wdgt = (new CWidget())
			->setTitle(_('IT services'))
			->setControls($r_form)
			->addItem(BR())
			->addItem($tree->getHTML())
			->show();
	}
	else {
		error(_('Cannot format Tree. Check logic structure in service links.'));
	}
}

require_once dirname(__FILE__).'/include/page_footer.php';
