<?php
/**********************************************************************
    Copyright (C) FrontAccounting, LLC.
	Released under the terms of the GNU Affero General Public License,
	AGPL, as published by the Free Software Foundation, either version 
	3 of the License, or (at your option) any later version.
    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  
    See the License here <http://www.gnu.org/licenses/agpl-3.0.html>.
***********************************************************************/
$page_security = 2;
// ----------------------------------------------------------------
// $ Revision:	2.0 $
// Creator:	Joe Hunt
// date_:	2005-05-19
// Title:	Tax Report
// ----------------------------------------------------------------
$path_to_root="..";

include_once($path_to_root . "/includes/session.inc");
include_once($path_to_root . "/includes/date_functions.inc");
include_once($path_to_root . "/includes/data_checks.inc");
include_once($path_to_root . "/gl/includes/gl_db.inc");

//------------------------------------------------------------------


print_tax_report();

function getTaxTransactions($from, $to)
{
	$fromdate = date2sql($from);
	$todate = date2sql($to);

	$sql = "SELECT taxrec.*, IF(ISNULL(dtrans.rate),IF(ISNULL(strans.rate), taxrec.amount, taxrec.amount*strans.rate), 
				taxrec.amount*dtrans.rate) AS amount,
	            IF(ISNULL(dtrans.rate),IF(ISNULL(strans.rate), taxrec.net_amount,taxrec.net_amount*strans.rate),
	            taxrec.net_amount*dtrans.rate) AS net_amount,
				stype.type_name,
				if(supp.supp_name is null, debt.name, supp.supp_name) as name,
				branch.br_name
		FROM ".TB_PREF."trans_tax_details taxrec
		LEFT JOIN ".TB_PREF."supp_trans strans
			ON taxrec.trans_no=strans.trans_no AND taxrec.trans_type=strans.type
		LEFT JOIN ".TB_PREF."suppliers as supp ON strans.supplier_id=supp.supplier_id
		LEFT JOIN ".TB_PREF."debtor_trans dtrans
			ON taxrec.trans_no=dtrans.trans_no AND taxrec.trans_type=dtrans.type
		LEFT JOIN ".TB_PREF."debtors_master as debt ON dtrans.debtor_no=debt.debtor_no
		LEFT JOIN ".TB_PREF."cust_branch as branch ON dtrans.branch_code=branch.branch_code,
		".TB_PREF."sys_types stype
		WHERE taxrec.trans_type=stype.type_id
			AND taxrec.trans_type != 13
			AND taxrec.tran_date >= '$fromdate'
			AND taxrec.tran_date <= '$todate'
		ORDER BY taxrec.tran_date";
//display_error($sql);
    return db_query($sql,"No transactions were returned");
}

function getTaxTypes()
{
	$sql = "SELECT * FROM ".TB_PREF."tax_types ORDER BY id";
    return db_query($sql,"No transactions were returned");
}

function getTaxInfo($id)
{
	$sql = "SELECT * FROM ".TB_PREF."tax_types WHERE id=$id";
    $result = db_query($sql,"No transactions were returned");
    return db_fetch($result);
}

//----------------------------------------------------------------------------------------------------

function print_tax_report()
{
	global $path_to_root, $trans_dir;
	
	
	include_once($path_to_root . "/reporting/includes/pdf_report.inc");

	$rep = new FrontReport(_('Tax Report'), "TaxReport.pdf", user_pagesize());

	$from = $_POST['PARAM_0'];
	$to = $_POST['PARAM_1'];
	$summaryOnly = $_POST['PARAM_2'];
	$comments = $_POST['PARAM_3'];
	$dec = user_price_dec();

	if ($summaryOnly == 1)
		$summary = _('Summary Only');
	else
		$summary = _('Detailed Report');

	$res = getTaxTypes();

	$taxes = array();
	while ($tax=db_fetch($res))
		$taxes[$tax['id']] = array('in'=>0, 'out'=>0, 'taxin'=>0, 'taxout'=>0);

	if (!$summaryOnly)
	{
		$cols = array(0, 80, 130, 180, 290, 370, 455, 505, 555);

		$headers = array(_('Trans Type'), _('Ref'), _('Date'), _('Name'), _('Branch Name'),
			_('Net'), _('Rate'), _('Tax'));
		$aligns = array('left', 'left', 'left', 'left', 'left', 'right', 'right', 'right');

		$params =   array( 	0 => $comments,
							1 => array('text' => _('Period'), 'from' => $from, 'to' => $to),
							2 => array('text' => _('Type'), 'from' => $summary, 'to' => ''));

		$rep->Font();
		$rep->Info($params, $cols, $headers, $aligns);
		$rep->Header();
	}
	
	$totalnet = 0.0;
	$totaltax = 0.0;
	$transactions = getTaxTransactions($from, $to);

	while ($trans=db_fetch($transactions))
	{
		if (in_array($trans['trans_type'], array(11,20,1))) {
			$trans['net_amount'] *= -1;
			$trans['amount'] *= -1;
		}
		
		if (!$summaryOnly)
		{
			$rep->TextCol(0, 1,	$trans['type_name']);
			$rep->TextCol(1, 2,	$trans['memo']);
			$rep->TextCol(2, 3,	sql2date($trans['tran_date']));
			$rep->TextCol(3, 4,	$trans['name']);
			$rep->TextCol(4, 5,	$trans['br_name']);

			$rep->TextCol(5, 6,	number_format2($trans['net_amount'], $dec));
			$rep->TextCol(6, 7,	number_format2($trans['rate'], $dec));
			$rep->TextCol(7, 8,	number_format2($trans['amount'], $dec));

			$rep->NewLine();

			if ($rep->row < $rep->bottomMargin + $rep->lineHeight)
			{
				$rep->Line($rep->row - 2);
				$rep->Header();
			}
		}
		if ($trans['net_amount'] > 0) {
			$taxes[$trans['tax_type_id']]['taxout'] += $trans['amount'];
			$taxes[$trans['tax_type_id']]['out'] += $trans['net_amount'];
		} else {
			$taxes[$trans['tax_type_id']]['taxin'] -= $trans['amount'];
			$taxes[$trans['tax_type_id']]['in'] -= $trans['net_amount'];
		}
		
		$totalnet += $trans['net_amount'];
		$totaltax += $trans['amount'];
	}
	
	// Summary
	$cols2 = array(0, 100, 180,	260, 340, 420, 500);

	$headers2 = array(_('Tax Rate'), _('Outputs'), _('Output Tax'),	_('Inputs'), _('Input Tax'), _('Net Tax'));

	$aligns2 = array('left', 'right', 'right', 'right',	'right', 'right', 'right');

	for ($i = 0; $i < count($cols2); $i++)
		$rep->cols[$i] = $rep->leftMargin + $cols2[$i];

	$rep->headers = $headers2;
	$rep->aligns = $aligns2;
	$rep->Header();

	$taxtotal = 0;
	foreach( $taxes as $id=>$sum)
	{
		$tx = getTaxInfo($id);
		
		$rep->TextCol(0, 1, $tx['name'] . " " . number_format2($tx['rate'], $dec) . "%");
		$rep->TextCol(1, 2, number_format2($sum['out'], $dec));
		$rep->TextCol(2, 3,number_format2($sum['taxout'], $dec));
		$rep->TextCol(3, 4, number_format2($sum['in'], $dec));
		$rep->TextCol(4, 5,number_format2($sum['taxin'], $dec)); 
		$rep->TextCol(5, 6, number_format2($sum['taxout']-$sum['taxin'], $dec));
		$taxtotal += $sum['taxout']-$sum['taxin'];
		$rep->NewLine();
	}

	$rep->Font('bold');
	$rep->NewLine();
	$rep->Line($rep->row + $rep->lineHeight);
	$rep->TextCol(3, 5,	_("Total payable or refund"));
	$rep->TextCol(5, 6,	number_format2($taxtotal, $dec));
	$rep->Line($rep->row - 5);
	$rep->Font();
	$rep->NewLine();

	$locale = $path_to_root . "/lang/" . $_SESSION['language']->code . "/locale.inc";
	if (file_exists($locale))
	{
		$taxinclude = true;
		include($locale);
		
//		if (function_exists("TaxFunction"))
//			TaxFunction();
		
	}

	$rep->End();
}

?>