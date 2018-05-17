<?php
/**
 * @file
 * Provides ExternalModule class for SuspensionWarning.
 */

namespace SuspensionWarning\ExternalModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;

/**
 * ExternalModule class for SuspensionWarning.
 */
class ExternalModule extends AbstractExternalModule {

	/* handle users that have expiration dates: which of the two have priority*/
	public function warn_users_account_suspension_cron()
	{
		global $project_contact_email, $lang, $auth_meth_global, $suspend_users_inactive_type, $suspend_users_inactive_days,
			   $suspend_users_inactive_send_email;

		// If feature is not enabled, then return
		if ($suspend_users_inactive_type == '' || !is_numeric($suspend_users_inactive_days) || $suspend_users_inactive_days < 1) return;

		$number_of_days_before_notifications_start = min(30, $suspend_users_inactive_days);

		// Static number of days before expiration occurs to warn them (first warning, then second warning)

		$warning_days = array(User::USER_EXPIRE_FIRST_WARNING_DAYS, User::USER_EXPIRE_SECOND_WARNING_DAYS); // e.g. 14 days, then 2 days

		// Initialize count
		$numUsersEmailed = 0;
		$today = date("Y-m-d");
		$x_days_from_now = date("Y-m-d", mktime(date("H"),date("i"),date("s"),date("m"),date("d")+$days_before_expiration,date("Y")));
		// Instantiate email object
		$email = new Message();
		$email->setFrom($project_contact_email);
		// Query users that wille expire *exactly* x days from today (since this will only run once per day)
		$sql = "select username, user_email, user_sponsor, user_firstname, user_lastname, user_lastactivity, user_lastlogin
				from redcap_user_information where user_suspended_time is null and ((user_lastactivity is not null and DATEDIFF(day, GETDATE(), user_lastactivity) <= '$number_of_days_before_notifications_start') or (user_lastlogin is not null and DATEDIFF(day, GETDATE(), user_lastlogin) <= '$number_of_days_before_notifications_start'))";
		$q = db_query($sql);
		$numUsersEmailed += db_num_rows($q);
		while ($row = db_fetch_assoc($q))
		{
			$most_recent_access_date = max($row['user_lastlogin'], $row['user_lastactivity']);

			// Email the user to warn them every 5 days and 2 days before the account will be suspended
			if ($row['user_email'] != '' && ($most_recent_access_date % 5 == 0 || $most_recent_access_date < 2))
			{
				// Set date and time x days from now
				$mktime = strtotime($row['user_expiration']);
				$x_days_from_now_friendly = date("l, F j, Y", $mktime);
				$x_time_from_now_friendly = date("g:i A", $mktime);
				// Determine if user has a sponsor with a valid email address
				$hasSponsor = false;
				if ($row['user_sponsor'] != '') {
					// Get sponsor's email address
					$sponsorUserInfo = User::getUserInfo($row['user_sponsor']);
					if ($sponsorUserInfo !== false && $sponsorUserInfo['user_email'] != '') {
						$hasSponsor = true;
					}
				}
				// Send email to user and/or user+sponsor
				if (!$hasSponsor) {
					// EMAIL USER ONLY
					$email->setCc("");
					$emailContents =   "{$lang['cron_02']}<br><br>{$lang['cron_03']} \"<b>{$row['username']}</b>\"
										(<b>{$row['user_firstname']} {$row['user_lastname']}</b>) {$lang['cron_06']}
										<b>$x_days_from_now_friendly ($x_time_from_now_friendly)</b>{$lang['period']}
										{$lang['cron_23']} {$lang['cron_24']} <a href=\"".APP_PATH_WEBROOT_FULL."\">".APP_PATH_WEBROOT_FULL."</a> {$lang['cron_05']}";
				} else {
					// EMAIL USER AND CC SPONSOR
					$email->setCc($sponsorUserInfo['user_email']);
					$emailContents =   "{$lang['cron_02']}<br><br>{$lang['cron_13']} \"<b>{$row['username']}</b>\"
										(<b>{$row['user_firstname']} {$row['user_lastname']}</b>) {$lang['cron_06']}
										<b>$x_days_from_now_friendly ($x_time_from_now_friendly)</b>{$lang['period']}
										{$lang['cron_23']} {$lang['cron_14']} \"<b>{$sponsorUserInfo['username']}</b>\"
										(<b>{$sponsorUserInfo['user_firstname']} {$sponsorUserInfo['user_lastname']}</b>){$lang['cron_15']}
										<a href=\"".APP_PATH_WEBROOT_FULL."\">".APP_PATH_WEBROOT_FULL."</a> {$lang['cron_05']}";

				}
				// Send the email
				$email->setTo($row['user_email']);
				$email->setSubject("[REDCap] {$row['username']}{$lang['cron_16']} $days_before_expiration {$lang['scheduling_25']}");
				$email->setBody($emailContents, true);
				$email->send();
			}
		}
		
		// Set cron job message
		if ($numUsersEmailed > 0) {
			$GLOBALS['redcapCronJobReturnMsg'] = "$numUsersEmailed users were emailed to warn them of their upcoming account expiration";
		}
	}
}