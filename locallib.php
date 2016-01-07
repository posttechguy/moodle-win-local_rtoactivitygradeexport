<?php
/**
  * Export activity grades to CSV file
  *
  * Local library definitions
  *
  * @package    local_rtoactivitygradeexport
  * @author     Bevan Holman <bevan@pukunui.com>, Pukunui
  * @copyright  2015 onwards, Pukunui
  * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
*/

/**
  * Write the CSV output to file
  *
  * @param string $csv  the csv data
  * @return boolean  success?
*/
function local_rtoactivitygradeexport_write_csv_to_file($runhow, $data) {
  global $CFG, $DB;

  $config = get_config('local_rtoactivitygradeexport');

  if (($runhow == 'auto' and $config->ismanual) or ($runhow == 'manual' and empty($config->ismanual))) {
    return false;
  }

  if (empty($config->csvlocation)) {
      $config->csvlocation = $CFG->dataroot.'/rtoactivitygradeexport';
  }
  if (!isset($config->csvprefix)) {
      $config->csvprefix = '';
  }
  if (!isset($config->lastrun)) {
      // First time run we get all data.
      $config->lastrun = 0;
  }
  // Open the file for writing.
  $filename = $config->csvlocation.'/'.$config->csvprefix.date("Ymd").'-'.date("His").'.csv';
  if ($fh = fopen($filename, 'w')) {

      // Write the headers first.
      fwrite($fh, implode(',', local_rtoactivitygradeexport_get_csv_headers())."\r\n");

      $rs = local_rtoactivitygradeexport_get_data($config->lastrun, $data);

      if ($rs->valid()) {

          $strnotattempted = get_string('notattempted', 'local_rtoactivitygradeexport');

          // Cycle through data and add to file.
          foreach ($rs as $r) {
              // Manually manipulate the grade.
              // We could do this via the grade API but that level of complexity is not required here.
              if (!empty($r->finalgrade)) {
                  if (!empty($r->scale)) {
                      $scalearray = explode(',', $r->scale);
                      $result = $scalearray[$r->finalgrade - 1];
                  } else {
                      $result = $r->finalgrade;
                  }
              } else {
                  $result = $strnotattempted;
              }

              // Format the time.
              $time = (!empty($r->completiontime)) ? date('Y-m-d', $r->completiontime) : '';
/*
              $grouplength = strlen($r->groupname);
              $strtempl = ($grouplength - 8);
              $teachername = substr($r->groupname, 0, $strtempl-1);
              $startdate = substr($r->groupname, $strtempl);

              // Write the line to CSV file.
              fwrite($fh,
                      implode(',',
                              array(
                                  $r->idnumber,
                                  $r->firstname,
                                  $r->lastname,
                                  $r->department,
                                  $r->courseshortname,
                                  $r->groupname,
                                  $startdate,
                                  $r->groupdesc,
                                  $teachername,
                                  $r->itemname,
                                  $r->finalgrade,
                                  $time
                              )
                      )."\r\n"
              );
*/
              // Write the line to CSV file.
              fwrite($fh,
                      implode(',',
                              array(
                                  $r->idnumber,
                                  $r->firstname,
                                  $r->lastname,
                                  $r->department,
                                  $r->courseshortname,
                                  $r->groupname,
                                  $startdate,
                                  $r->groupdesc,
                                  $teachername,
                                  $r->itemname,
                                  $r->finalgrade,
                                  $time
                              )
                      )."\r\n"
              );

          }
//                                     $r->finalgradepercent,

          // Close the recordset to free up RDBMS memory.
          $rs->close();
      }
      // Close the file.
      fclose($fh);

      // Set the last run time.
      if ($runhow == 'auto') set_config('local_rtoactivitygradeexport', 'lastrun', time());

      return true;
  } else {
      return false;
  }
}


/**
 * Return a recordset with the grade, group, enrolment data.
 * We use a recrodset to minimise memory usage as this report may get quite large.
 *
 * @param integer $from  timestamp
 * @return object  $DB recordset
 */
function local_rtoactivitygradeexport_get_data($from, $data = null) {
    global $DB;
/*
    $sql = "
        SELECT
            CONCAT(c.id, u.idnumber, g.name, gi.id), u.id, u.lastname, u.firstname,
            c.id as courseid, c.fullname as coursefullname,
            c.shortname as courseshortname,  u.idnumber, u.department,
            g.name as groupname, g.description as groupdesc,
            gi.itemname, gi.itemmodule, gg.finalgrade,gg.rawgrademax, round(gg.finalgrade/gg.rawgrademax*100) as finalgradepercent,
            gg.timemodified as completiontime
        FROM mdl_grade_grades AS gg
        JOIN mdl_grade_items AS gi ON gg.itemid = gi.id
        JOIN mdl_user AS u ON gg.userid = u.id
        JOIN mdl_role_assignments AS ra ON ra.userid = u.id
        JOIN mdl_context AS cx ON cx.id = ra.contextid
        JOIN mdl_groups_members AS gm ON gm.userid = u.id
        JOIN mdl_groups AS g ON g.id = gm.groupid %%GROUPCLAUSE%%
        JOIN mdl_course AS c ON c.id = g.courseid AND cx.instanceid = c.id
        WHERE cx.contextlevel = 50 %%COURSECLAUSE%%
        AND (gi.itemmodule = 'quiz'  OR gi.itemmodule = 'assign')
        AND gi.hidden = 0 AND gg.timemodified >= :from

        GROUP BY idnumber,itemname
        ORDER BY 1,2, 3, 4, gi.sortorder
    ";
*/
    $sql = "
        SELECT
            CONCAT_WS('-', c.id, u.idnumber, g.name, y.id),
            u.id,
            u.lastname,
            u.firstname,
            c.id as courseid,
            c.fullname as coursefullname,
            c.shortname as courseshortname,
            u.idnumber,
            u.department,
            g.name as groupname,
            g.description as groupdesc,
            y.itemname,
            y.itemmodule,
            y.finalgrade,
            y.rawgrademax,
            round(y.finalgrade/y.rawgrademax*100) as finalgradepercent,
            y.timemodified as completiontime
        FROM mdl_user u
        JOIN
        (
            (
                SELECT ue.userid as userid, e.courseid as courseid, NULL AS itemname
                FROM mdl_user_enrolments ue
                JOIN mdl_enrol e ON ue.enrolid = e.id
                WHERE ue.timeend IS NOT NULL AND ue.timemodified >= :from1
            )
            UNION
            (
                SELECT gg.userid as userid, gi.courseid as courseid, gi.itemname
                FROM mdl_grade_grades gg
                JOIN mdl_grade_items gi
                ON gi.id = gg.itemid
                WHERE gg.timemodified IS NOT NULL
                AND gg.timemodified >= :from2
                AND gi.itemtype = 'mod'
            )
        ) AS x ON x.userid = u.id
        JOIN mdl_course c ON x.courseid = c.id %%COURSECLAUSE%%
        LEFT JOIN mdl_groups g ON g.courseid = c.id %%GROUPCLAUSE%%
        JOIN mdl_groups_members gm ON gm.groupid = g.id  AND gm.userid = u.id
        LEFT JOIN
        (
            SELECT
                gi.id,
                gi.itemtype,
                gi.itemname,
                gi.scaleid,
                gi.itemmodule,
                gg.finalgrade,
                gg.rawgrademax,
                s.scale,
                gg.userid,
                gi.courseid,
                gg.timemodified,
                round(gg.finalgrade/gg.rawgrademax*100) as finalpercent
            FROM
            (
                mdl_grade_items gi
                JOIN mdl_grade_grades gg ON gg.itemid = gi.id
            )
            LEFT JOIN mdl_scale s ON gi.scaleid = s.id
            WHERE gi.itemtype = 'mod'
        ) as y ON x.userid = y.userid AND x.courseid = y.courseid
        GROUP BY 1,2,3,4,5,6,9,11
    ";

    $params = array();

    if ($data)
    {
        $params['from1'] = time() - 60*60*24*185;
        $params['from2'] = time() - 60*60*24*185;
        $params['course'] = $data->course;
        $params['group'] = $data->group;

        $sql = str_replace("%%COURSECLAUSE%%", ($data->course) ? " AND x.courseid = :course " : "", $sql);
        $sql = str_replace("%%GROUPCLAUSE%%", ($data->group != "All") ? " AND g.name = :group " : "", $sql);
    }
    else
    {
        // Gets the last run time, removes the seconds from today (which is usually run early in the morning),
        // yesterday, and the day before, (so around 48 hours).
        // It will then allow the export to get the records for the last two days
        //                          seconds of today   seconds of yesterday     seconds of day before that
        $runfrom         = $from - ($from % 86400)      - 86400                  - 86400;
    //    $runfrom          = $from - 60*60*24*185;

        $params['from1'] = $runfrom;
        $params['from2'] = $runfrom;

        $sql = str_replace("%%COURSECLAUSE%%", "", $sql);
        $sql = str_replace("%%GROUPCLAUSE%%", "", $sql);
    }
    /*
    if ($_SERVER['REMOTE_ADDR'] == '203.59.120.7')
    {
        print_object($params);
         echo "<pre>$sql</pre>";
    }
    */


// $DB->set_debug(true);
$r = $DB->get_recordset_sql($sql, $params);
// $DB->set_debug(false);

    return $r;
}


/**
 * Return the CSV headers
 *
 * @return array
 */
function local_rtoactivitygradeexport_get_csv_headers() {

    return array(
        get_string('studentid',         'local_rtoactivitygradeexport'),
        get_string('firstname',         'local_rtoactivitygradeexport'),
        get_string('lastname',          'local_rtoactivitygradeexport'),
        get_string('programcourseid',   'local_rtoactivitygradeexport'),
        get_string('subjectid',         'local_rtoactivitygradeexport'),
        get_string('batch',             'local_rtoactivitygradeexport'),
        get_string('classstartdate',    'local_rtoactivitygradeexport'),
        get_string('classid',           'local_rtoactivitygradeexport'),
        get_string('userteacherid',     'local_rtoactivitygradeexport'),
        get_string('taskname',          'local_rtoactivitygradeexport'),
        get_string('marks',             'local_rtoactivitygradeexport'),
        get_string('completedtime',     'local_rtoactivitygradeexport'),
    );
}
