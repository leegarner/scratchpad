<?php
/**
* glFusion CMS
*
* glFusion Rating Interface
*
* @license Creative Commons Attribution 3.0 License.
*     http://creativecommons.org/licenses/by/3.0/                              |
*
*  Copyright (C) 2008-2019 by the following authors:
*   Mark R. Evans   mark AT glfusion DOT org
*
*  Based on original work Copyright (C) 2006,2007,2008 by the following authors:
*   Ryan Masuga, masugadesign.com  - ryan@masugadesign.com
*   Masuga Design
*      http://masugadesign.com/the-lab/scripts/unobtrusive-ajax-star-rating-bar
*   Komodo Media (http://komodomedia.com)
*   Climax Designs (http://slim.climaxdesigns.com/)
*   Ben Nolan (http://bennolan.com/behaviour/) for Behavio(u)r!
*
*  Homepage for this script:
*  http://www.masugadesign.com/the-lab/scripts/unobtrusive-ajax-star-rating-bar/
*
*  This (Unobtusive) AJAX Rating Bar script is licensed under the
*  Creative Commons Attribution 3.0 License
*    http://creativecommons.org/licenses/by/3.0/
*
*  What that means is: Use these files however you want, but don't
*  redistribute without the proper credits, please. I'd appreciate hearing
*  from you if you're using this script.
*
*/

require_once 'lib-common.php';

if ( !isset($_CONF['rating_speedlimit']) ) {
    $_CONF['rating_speedlimit'] = 15;
}

header("Cache-Control: no-cache");
header("Pragma: nocache");

$status     = 0;
$vote_sent  = (int)$_GET['j'];
$id_sent    = COM_applyFilter($_GET['q']);
$ip_sent    = preg_replace("/[^0-9\.\:]/","",$_GET['t']);
$units      = (int)$_GET['c'];
$size       = preg_replace("/[^0-9a-zA-Z]/","",$_GET['s']);
$plugin     = isset($_GET['p']) ? COM_applyFilter($_GET['p']) : '';
$ip         = $_SERVER['REAL_ADDR'];
$ratingdate = time();
$uid        = isset($_USER['uid']) ? (int)$_USER['uid'] : 1;

if ($plugin == '') {
    die('no type specified');
}
if ($vote_sent > $units || $vote_sent < 1) {
    // kill the script because normal users will never see this.
    die("Sorry, vote appears to be invalid.");
}

$canRate = PLG_canUserRate($plugin, $id_sent, $uid);
$Rater = \glFusion\Rater::create($plugin, $id_sent);

if ($canRate) {
    // Check if the user has already voted.
    $status = $Rater->userHasVoted();

    COM_clearSpeedlimit($_CONF['rating_speedlimit'], 'rate');
    $last = COM_checkSpeedlimit('rate');
    if ($last == 0 && !$status && $ip == $ip_sent) {
        //if the user hasn't yet voted, then vote normally...
        // keep votes within range, make sure IP matches - no monkey business!
        $Rater->addVote($vote_sent);
        COM_updateSpeedlimit ('rate');
    } else {
        $status = 2;
    }
} else {
    $status = 3;
}
$total_votes = $Rater->getTotalVotes();
$new_rating = $Rater->getRating();
$tense = ($total_votes == 1) ? $LANG13['vote'] : $LANG13['votes'];

// set message
switch ($status) {
case 1:     // either IP or UID has already voted
    $message = "<script>alert('". $LANG13['ip_rated'] . "');</script>";
    break;
case 2:     // voting too frequently
    $message = "<script>alert('" .
        sprintf($LANG13['rate_speedlimit'],$last,$_CONF['rating_speedlimit']) .
        "');</script>";
    break;
case 3:     // no permission to vote or your already own the item
    $message = "<script>alert('".$LANG13['own_rated']."');</script>";
    break;
default:    // vote recorded normally
    $message = '<br><span class="thanks">&nbsp;' . $LANG13['thanks_for_vote'] . '</span>';
    break;
}

// Updating the ratingbar and echo back to the javascript
$newBar = $Rater->withWrapper(0)->withSize($size)->Render();
echo implode("\n", array($newBar, $message));
?>
root@dogbert:/var/www/sites/dev/public_html# 
root@dogbert:/var/www/sites/dev/public_html# cat ../private/classes/Rater.php 
<?php
/**
* glFusion CMS
*
* glFusion Rating Interface
*
* @license Creative Commons Attribution 3.0 License.
*     http://creativecommons.org/licenses/by/3.0/                              |
*
*  Copyright (C) 2008-2019 by the following authors:
*   Mark R. Evans   mark AT glfusion DOT org
*
*  Based on original work Copyright (C) 2006,2007,2008 by the following authors:
*   Ryan Masuga, masugadesign.com  - ryan@masugadesign.com
*   Masuga Design
*      http://masugadesign.com/the-lab/scripts/unobtrusive-ajax-star-rating-bar
*   Komodo Media (http://komodomedia.com)
*   Climax Designs (http://slim.climaxdesigns.com/)
*   Ben Nolan (http://bennolan.com/behaviour/) for Behavio(u)r!
*
*  Homepage for this script:
*  http://www.masugadesign.com/the-lab/scripts/unobtrusive-ajax-star-rating-bar/
*
*  This (Unobtusive) AJAX Rating Bar script is licensed under the
*  Creative Commons Attribution 3.0 License
*    http://creativecommons.org/licenses/by/3.0/
*
*  What that means is: Use these files however you want, but don't
*  redistribute without the proper credits, please. I'd appreciate hearing
*  from you if you're using this script.
*
*/
namespace glFusion;
use Template;

/**
 * Class to manage ratings and display the ratingbar.
 * @package glfusion
 */
class Rater
{
    /** Record ID of the item rating.
     * @var integer */
    private $rating_id = 0;

    /** Value of the current rating (total_value / total_votes).
     * @var float */
    private $rating = 0;

    /** Type of item being rated, e.g. plugin name.
     * @var string */
    private $item_type = '';

    /** Item ID.
     * @var string */
    private $item_id = '';

    /** User ID stored in Votes table.
     * @var integer */
    private $uid = 0;

    /** IP Address of the voter.
     * @var string */
    private $ip = '';

    /** Total number of votes given.
     * @var integer */
    private $total_votes = 0;

    /** Total value of all votes.
     * @var integer */
    private $total_value = 0;

    /** Flag to indicate that the current user has already voted.
     * @var boolean */
    private $voted = 0;

    /** Number of rating stars to show.
     * @var integer */
    private $units = 5;

    /** Flag to indicate that the rating bar is static (no voting).
     * @var boolean */
    private $static = 0;

    /** Size of the stars shown.
     * 'sm' indicates small icons, anything else indicates normal.
     * Normal size uses the uk-icon-small class.
     * @var string */
    private $size = 'med';

    /** Icon size class.
     * @var string */
    private $icon_size = 'uk-icon-small';

    /** Icon width in pixels.
     * @var integer */
    private $icon_width = 15;

    /** Flag to wrap the rating bar in a wrapper.
     * @var boolean */
    private $wrapper = 1;

    /** Flag to indicate that the javascript has been included.
     * @var boolean */
    private static $have_js = 0;


    /**
     * Set the rating ID.
     *
     * @param   integer $id     Rating record ID
     * @return  object  $this
     */
    public function withRatingID($id)
    {
        $this->rating_id = (int)$id;
        return $this;
    }


    /**
     * Get the rating record ID.
     *
     * @return  integer     Record ID of the rating
     */
    public function getRatingID()
    {
        return (int)$this->rating_id;
    }


    /**
     * Set the item ID.
     *
     * @param   string  $item_id    Item ID
     * @return  object  $this
     */
    public function withItemID($item_id)
    {
        $this->item_id = $item_id;
        return $this;
    }


    /**
     * Get the item ID.
     *
     * @return  string  $item_id    Item ID
     */
    public function getItemID()
    {
        return $this->item_id;
    }


    /**
     * Set the user ID.
     *
     * @param   integer $uid        User ID
     * @return  object  $this
     */
    public function withUid($uid)
    {
        $this->uid = (int)$uid;
        return $this;
    }


    /**
     * Get the rating user ID.
     *
     * @return  integer     User ID
     */
    public function getUid()
    {
        return (int)$this->uid;
    }


    /**
     * Set the item type, e.g. plugin name.
     *
     * @param   string  $type   Item type/Plugin name
     * @return  object  $this
     */
    public function withItemType($type)
    {
        $this->item_type = $type;
        return $this;
    }


    /**
     * Get the item type/plugin name.
     *
     * @return  string      Item type
     */
    public function getItemType()
    {
        return $this->item_type;
    }


    /**
     * Set the number of units (stars) to be used.
     *
     * @param   integer $units      Number of units
     * @return  object  $this
     */
    public function withUnits($units)
    {
        $this->units = (int)$units;
        return $this;
    }


    /**
     * Set the voter's IP address.
     *
     * @param   string  $ip     IP address, obtained from `$_SERVER` if empty
     * @return  object  $this
     */
    public function withIpAddress($ip=NULL)
    {
        if ($ip === NULL) {
            $ip = $_SERVER['REAL_ADDR'];
        }
        $this->ip = $ip;
        return $this;
    }


    /**
     * Get the voter's IP address.
     *
     * @return  string      IP address
     */
    public function getIpAddress()
    {
        return $this->ip;
    }


    /**
     * Set the total votes received for the item.
     *
     * @param   integer $count  Total vote count for the current item
     * @return  object  $this
     */
    public function withTotalVotes($count)
    {
        $this->total_votes = (int)$count;
        return $this;
    }


    /**
     * Get the total vote count for the current item.
     *
     * @return  integer     Number of votes received
     */
    public function getTotalVotes()
    {
        return (int)$this->total_votes;
    }


    /**
     * Set the current rating for the item.
     *
     * @param   float   $rating     Current rating
     * @return  object  $this
     */
    public function withRating($rating)
    {
        $this->rating = (float)$rating;
        return $this;
    }


    /**
     * Get the current rating for the item.
     *
     * @return  float   Current rating
     */
    public function getRating()
    {
        return (float)$this->rating;
    }


    /**
     * Set the flag to wrap the output in `<div>` sections or not.
     * Wrapper is normally used for the initial display, but not when
     * called via AJAX.
     *
     * @param   boolean $flag   True to wrap, False to not
     * @return  object  $this
     */
    public function withWrapper($flag)
    {
        $this->wrapper = $flag ? 1 : 0;
        return $this;
    }


    /**
     * Set the static display flag.
     *
     * @param   boolean $flag   True to show a static display, False for normal
     * @return  object  $this
     */
    public function withStatic($flag)
    {
        $this->static = $flag ? 1 : 0;
        return $this;
    }


    /**
     * Set the size of the icons to use.
     *
     * @param   string  $size   Size indicator (sm, med, lg)
     * @return  object  $this
     */
    public function withSize($size='sm')
    {
        switch ($size) {
        case 'sm':
            $this->icon_width = 15;
            $this->icon_size = '';
            break;
        case 'med':
        case 'medium':
            $this->icon_width = 20;
            $this->icon_size = 'uk-icon-small';
            break;
        case 'lg':
        case 'large':
            $this->icon_width = 25;
            $this->icon_size = 'uk-icon-medium';
            break;
        }
        $this->size = $size;
        return $this;
    }


    /**
     * Factory function to create a rating bar.
     * If tye type and ID are supplied then the database is read.
     *
     * @param   string  $item_type  Type of item (plugin name)
     * @param   string  $item_id    Item ID
     * @return  object      New Rater object
     */
    public static function create($item_type = NULL, $item_id = NULL)
    {
        global $_USER;

        if ($item_type !== NULL && $item_id !== NULL) {
            $retval = self::getItemRating($item_type, $item_id);
        } else {
            $retval = new self;
        }
        $retval->withIpAddress($_SERVER['REAL_ADDR']);
        $retval->withUid($_USER['uid']);
        return $retval;
    }


    /**
     * Render the rating bar.
     *
     * @return  string      HTML for rating bar
     */
    public function Render()
    {
        global $_USER, $_CONF, $LANG13;

        // determine whether the user has voted, so we know how to draw the ul/li
        // now draw the rating bar
        $has_voted = $this->userHasVoted();
        $text = '';
        $rating1 = @number_format($this->rating, 2);
        $tense = ($this->total_votes == 1) ? $LANG13['vote'] : $LANG13['votes'];
        if ($this->static || $has_voted) {
            $rater_cls = '';
            $voting = 0;
        } else {
            $rater_cls = 'ratingstar';
            $voting = 1;
        }

        $retval = '';
        $T = new Template($_CONF['path_layout']);
        $T->set_file('rater', 'ratingbar.thtml');
        $T->set_var(array(
            'wrapper'   => $this->wrapper,
            'item_id'   => $this->item_id,
            'item_type' => $this->item_type,
            'ip_address' => $this->ip,
            'units'     => $this->units,
            'voting'    => $voting,
            'tense'     => $tense,
            'rating'    => $rating1,
            'total_votes' => $this->total_votes,
            'bar_size'  => $this->size,
            'need_js'   => self::$have_js ? 0 : 1,
        ) );
        self::$have_js = 1;

        $T->set_block('rater', 'ratingIcons', 'Icons');
        for ($i = $this->units; $i > ceil($this->rating); $i--) {
            $T->set_var(array(
                'checked' => 'unchecked',
                'points' => $i,
                'icon_width' => $this->icon_width,
                'icon_size' => $this->icon_size,
                'rater_cls' => $rater_cls,
            ) );
            $T->parse('Icons', 'ratingIcons', true);
        }
        if ($this->rating != (int)$this->rating) {
            $T->set_var(array(
                'checked' => 'half',
                'points' => $i,
                'icon_width' => $this->icon_width,
                'icon_size' => $this->icon_size,
                'rater_cls' => $rater_cls,
            ) );
            $T->parse('Icons', 'ratingIcons', true);
            $i--;
        }
        for (; $i >= 1; $i--) {
            $T->set_var(array(
                'checked' => 'checked',
                'points' => $i,
                'icon_width' => $this->icon_width,
                'icon_size' => $this->icon_size,
                'rater_cls' => $rater_cls,
            ) );
            $T->parse('Icons', 'ratingIcons', true);
        }

        $T->parse('output', 'rater');
        $retval .= $T->finish($T->get_var('output'));
        return $retval;
    }


    private function getStar($chk_type, $points)
    {
        /*if ($this->size == 'sm') {
            $size_cls = '';
            $width = '18px;';
        }*/
        $width=15;
        $rater_cls = $this->canvote ? 'ratingstar' : '';
        $size_cls = $this->size == 'sm' ? '' : 'uk-icon-medium';
        switch ($chk_type) {
        case 'checked':
            $icon = 'uk-icon-star';
            break;
        case 'unchecked':
            $icon = 'uk-icon-star-o';
            break;
        case 'half':
            $icon = 'uk-star-icon-half-o';
            break;
        }
        $retval = '<i style="width:' . $width . 'px;" ' .
            'class="' . $rater_cls .
            '" data-points="' . $points .
            '" uk-icon ' . $icon . ' ' . $size_cls . ' ' . $chk_cls . "'></i>";
        return $retval;
    }


    /**
    * Returns an array of all voting records for either a $type or an $item_id.
    *
    * @param        string      $type     plugin name
    * @param        string      $item_id  item id (optional)
    * @param        string      $sort     column to sort data by
    * @param        string      $sortdir  asc or desc
    * @param        array       $filterArray An array of fields => values for where clause
    * @return       array       an array of all voting records that match the search criteria
    *
    */
    public static function getVoteData($type, $item_id='', $sort='ratingdate', $sortdir = 'desc', $filterArray = '')
    {
        global $_TABLES;

        $whereClause = '';
        $retval = array();

        $validFields = array(
            'id','type','item_id','uid','vote','ip_address','ratingdate',
        );
        $validDirection = array(
            'asc','desc',
        );
        $type = DB_escapeString($type);
        $item_id = DB_escapeString($item_id);
        if (!in_array($sort,$validFields)) {
            $sort = 'ratingdate';
        }
        if (!in_array($sortdir,$validDirection)) {
            $sortdir = 'desc';
        }
        if ($item_id != '') {
            $whereClause = " AND item_id ='$item_id' ";
        }
        if (is_array($filterArray)) {
            foreach ($filterArray AS $bType=>$filter) {
                $whereClause .= ' ' . $bType . ' ' . $filter;
            }
        }

        $sql = "SELECT * FROM {$_TABLES['rating_votes']} AS r
            LEFT JOIN {$_TABLES['users']} AS u
            ON r.uid = u.uid
            WHERE type = '$type' $whereClause
            ORDER BY $sort $sortdir";
        $result = DB_query($sql);
        while ($row = DB_fetchArray($result, false)) {
            $retval[] = $row;
        }
        return $retval;
    }


    /**
    * Returns an array consisting of the rating_id, votes and rating.
    *
    * @param    string  $type     plugin name
    * @param    string  $item_id  item id
    * @return   object      Rater object
    */
    public static function getItemRating($type, $item_id)
    {
        global $_TABLES;

        $sql = "SELECT * FROM {$_TABLES['rating']}
            WHERE type='". DB_escapeString($type) . "'
            AND item_id='" . DB_escapeString($item_id) . "'";
        $result = DB_query($sql);
        $retval = (new self)->withItemType($type)->withItemID($item_id);
        if (DB_numRows($result) > 0) {
            $row = DB_fetchArray($result, false);
            $retval->withRatingID($row['id'])
                ->withTotalVotes($row['votes'])
                ->withRating($row['rating']);
        }
        return $retval;
    }


    /**
    * Check if user has already rated for an item.
    * Determines if user or IP has already rated the item.
    *
    * @return   boolean     True if user or ip has already rated, False if not
    */
    public function userHasVoted()
    {
        global $_TABLES, $_USER;

        $voted = 0;
        if (empty($ip)) {
            $ip = $_SERVER['REAL_ADDR'];
        }
        $ip = DB_escapeString($this->ip);
        $uid = (int)$this->uid;
        $item_id = DB_escapeString($this->item_id);

        if ( $uid == 1 ) {
            $sql = "SELECT id FROM {$_TABLES['rating_votes']}
                WHERE ip_address='$ip'
                AND item_id='$item_id'";
        } else {
            $sql = "SELECT id FROM {$_TABLES['rating_votes']}
                WHERE (uid=$uid OR ip_address='$ip')
                AND item_id='$item_id'";
        }
        $checkResult = DB_query($sql);
        if (DB_numRows($checkResult) > 0) {
            $voted = 1;
        } else {
            $voted = 0;
        }
        return $voted;
    }


    /**
    * Removes all rating data for an item.
    *
    * @param    string  $type       Plugin name (item type)
    * @param    string  $item_id    Item ID
    * @return   none
    */
    public static function reset($type, $item_id)
    {
        global $_TABLES;

        DB_delete(
            $_TABLES['rating'],
            array('type','item_id'),
            array($type,$item_id)
        );
        DB_delete(
            $_TABLES['rating_votes'],
            array('type','item_id'),
            array($type,$item_id)
        );
        PLG_itemRated($type, $item_id, 0, 0);
    }


    /**
    * Deletes a specific rating entry and recalculates the new rating.
    *
    * @param    string  $voteID     The ID of the rating_votes record
    * @return   bool        True if successful otherwise False
    */
    public static function delete($voteID)
    {
        global $_TABLES;

        $retval = false;

        $voteID = (int)$voteID;
        $result = DB_query(
            "SELECT * FROM {$_TABLES['rating_votes']}
            WHERE id = $voteID"
        );
        $row = DB_fetchArray($result, false);
        if (!$A) {
            return $retval;
        }

        $item_id = $row['item_id'];
        $type = $row['type'];
        $user_rating = $row['rating'];

        $Rating = self::create($type, $item_id);

        if ($Rating->getTotalVotes() > 0) {
            $tresult = DB_query(
                "SELECT SUM(rating), COUNT(item_id)
                FROM  {$_TABLES['rating_votes']}
                WHERE item_id = ".$item_id." AND type='".$type."'"
            );
            list($total_rating,$total_votes) = DB_fetchArray($tresult, false);
            $new_total_rating = $total_rating - $user_rating;
            $new_total_votes  = $total_votes  - 1;
            if ($new_total_rating > 0 && $new_total_votes > 0) {
                $new_rating = $new_total_rating / $new_total_votes;
                $votes = $new_total_votes;
            } else {
                $new_rating = 0;
                $new_total_votes = 0;
                $votes = 0;
            }
            $new_rating = number_format($new_rating, 2);
            $sql = "UPDATE {$_TABLES['rating']} SET
                votes=" . $new_total_votes . ",
                rating='" . (float)$new_rating . "'
                WHERE id = ". $rating_id;
            DB_query($sql);
            DB_delete($_TABLES['rating_votes'], 'id', $voteID);
            PLG_itemRated($type, $item_id, $new_rating, $votes);
            $retval = true;
        }
        return $retval;
    }


    /**
    * Add a new rating to an item.
    * This will calculate the new overall rating, update the vote table
    * with the user / ip info and ask the plugin to update its records.
    *
    * @param    integer $rating     Rating sent by user
    */
    public function addVote($rating)
    {
        global $_TABLES;

        if ($rating < 1) {
            return array($this->rating, $this->total_votes);
        }

        $ratingdate = time();

        $tresult = DB_query(
            "SELECT SUM(rating), COUNT(item_id)
            FROM  {$_TABLES['rating_votes']}
            WHERE item_id = '" . DB_escapeString($this->item_id) . "'
            AND type='" . DB_escapeString($this->item_type)."'"
        );
        if (DB_numRows($tresult) > 0) {
            list($total_rating,$total_votes) = DB_fetchArray($tresult);
        } else {
            $total_rating = 0;
            $total_votes  = 0;
        }
        $total_rating = (int)$total_rating + (int)$rating;
        $total_votes = (int)$total_votes + 1;
        if ($total_rating > 0 && $total_votes > 0) {
            $new_rating = $total_rating / $total_votes;
        } else {
            $new_rating = 0;
        }

        $new_rating = number_format($new_rating, 2);
        $this->withRating($new_rating);
        $this->withTotalVotes($total_votes);

        if ($this->getRatingID() != 0) {
            $sql = "UPDATE {$_TABLES['rating']}
                SET votes=" . $this->getTotalVotes() . ",
                rating='" . $this->getRating() . "'
                WHERE id = " . $this->getRatingID();
            DB_query($sql);
        } else {
            $sql = "SELECT MAX(id) + 1 AS newid FROM " . $_TABLES['rating'];
            $result = DB_query($sql);
            $row = DB_fetchArray( $result );
            $newid = (int)$row['newid'];
            if ( $newid < 1 ) {
                $newid = 1;
            }
            $this->withRatingID($newid);
            $sql = "INSERT INTO {$_TABLES['rating']} SET
                id = " . $this->getRatingID() . ",
                type = '" . DB_escapeString($this->getItemType()) . "',
                item_id = '" . DB_escapeString($this->getItemID()) . "',
                votes = " . $this->getTotalVotes() . ",
                rating = " . $this->getRating();
            DB_query($sql);
        }
        $sql = "INSERT INTO {$_TABLES['rating_votes']} SET
            type = '" . DB_escapeString($this->getItemType()) . "',
            item_id = '" . DB_escapeString($this->getItemID()) . "',
            rating = " . (float)$rating . ",
            uid = " . $this->getUid() . ",
            ip_address = '" . DB_escapeString($this->getIpAddress()) . "',
            ratingdate = '$ratingdate'";
        DB_query($sql);
        PLG_itemRated(
            $this->getItemType(),
            $this->getItemID(),
            $this->getRating(),
            $this->getTotalVotes()
        );
    }


    /**
    * Retrieve an array of item_id's the current user has rated.
    * This function will return an array of all the items the user
    * has rated for the specific type.
    *
    * @param    string  $type     Plugin name
    * @return   array       Array of item ids
    */
    public static function getRatedIds($type)
    {
        global $_TABLES, $_USER;

        $ip     = DB_escapeString($_SERVER['REAL_ADDR']);
        $uid    = isset($_USER['uid']) ? (int)$_USER['uid'] : 1;
        $type   = DB_escapeSTring($type);
        $ratedIds = array();
        if ($uid == 1) {
            $sql = "SELECT item_id FROM {$_TABLES['rating_votes']}
                WHERE type = '$type'
                AND ip_address ='$ip'";
        } else {
            $sql = "SELECT item_id FROM {$_TABLES['rating_votes']}
                WHERE type ='$type'
                AND (uid = $uid OR ip_address = '$ip')";
        }
        $result = DB_query($sql,1);
        while ( $row = DB_fetchArray($result) ) {
            $ratedIds[] = $row['item_id'];
        }
        return $ratedIds;
    }

}
