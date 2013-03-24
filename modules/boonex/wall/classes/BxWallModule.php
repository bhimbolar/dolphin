<?php
/**
 * Copyright (c) BoonEx Pty Limited - http://www.boonex.com/
 * CC-BY License - http://creativecommons.org/licenses/by/3.0/
 */

bx_import('BxDolModule');
bx_import('BxDolPaginate');
bx_import('BxDolRssFactory');
bx_import('BxDolAdminSettings');

require_once( BX_DIRECTORY_PATH_PLUGINS . 'Services_JSON.php' );

require_once('BxWallResponse.php');
require_once('BxWallCmts.php');

define('BX_WALL_FILTER_ALL', 'all');
define('BX_WALL_FILTER_OWNER', 'owner');
define('BX_WALL_FILTER_OTHER', 'other');

define('BX_WALL_VIEW_TIMELINE', 'timeline');
define('BX_WALL_VIEW_OUTLINE', 'outline');

define('BX_WALL_MEDIA_CATEGORY_NAME', 'wall');

define('BX_WALL_DIVIDER_ID', ',');
define('BX_WALL_DIVIDER_TIMELINE', '-');

class BxWallModule extends BxDolModule
{
    var $_iOwnerId;
    var $_sJsPostObject;
    var $_sJsViewObject;
    var $_aPostElements;
    var $_sJsOutlineObject;

    var $_sDividerTemplate;
    var $_sBalloonTemplate;
    var $_sCmtPostTemplate;
    var $_sCmtViewTemplate;
    var $_sCmtTemplate;

    /**
     * Constructor
     */
    function BxWallModule($aModule)
    {
        parent::BxDolModule($aModule);
        $this->_oConfig->init($this->_oDb);
        $this->_oTemplate->init($this);

        $this->_iOwnerId = 0;

        $this->_sJsPostObject = $this->_oConfig->getJsObject('post');
        $this->_sJsViewObject = $this->_oConfig->getJsObject('view');
        $this->_sJsOutlineObject = $this->_oConfig->getJsObject('outline');

        //--- Define Membership Actions ---//
        defineMembershipActions(array('timeline post comment', 'timeline delete comment'), 'ACTION_ID_');
    }

    /**
     *
     * Admin Settings Methods
     *
     */
    function getSettingsForm($mixedResult)
    {
        $iId = (int)$this->_oDb->getOne("SELECT `ID` FROM `sys_options_cats` WHERE `name`='Timeline'");
        if(empty($iId))
           return MsgBox(_t('_wall_msg_no_results'));

        $oSettings = new BxDolAdminSettings($iId, BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri() . 'admin');
        $sResult = $oSettings->getForm();

        if($mixedResult !== true && !empty($mixedResult))
            $sResult = $mixedResult . $sResult;

        return $sResult;
    }
    function setSettings($aData)
    {
        $iId = (int)$this->_oDb->getOne("SELECT `ID` FROM `sys_options_cats` WHERE `name`='Timeline'");
        if(empty($iId))
           return MsgBox(_t('_wall_msg_no_results'));

        $oSettings = new BxDolAdminSettings($iId);
        return $oSettings->saveChanges($_POST);
    }

    /**
     * ACTION METHODS
     * Post somthing on the wall.
     *
     * @return string with JavaScript code.
     */
    function actionPost()
    {
        $sResult = "parent." . $this->_sJsPostObject . "._loading(null, false);\n";

        $this->_iOwnerId = (int)$_POST['WallOwnerId'];
        if (!$this->_isCommentPostAllowed(true))
            return "<script>" . $sResult . "alert('" . bx_js_string(_t('_wall_msg_not_allowed_post')) . "');</script>";

        $sPostType = process_db_input($_POST['WallPostType'], BX_TAGS_STRIP);
        $sContentType = process_db_input($_POST['WallContentType'], BX_TAGS_STRIP);

        $sMethod = "_process" . ucfirst($sPostType) . ucfirst($sContentType);
        if(method_exists($this, $sMethod)) {
            $aResult = $this->$sMethod();
            if((int)$aResult['code'] == 0) {
                $iId = $this->_oDb->insertEvent(array(
                   'owner_id' => $this->_iOwnerId,
                   'object_id' => $aResult['object_id'],
                   'type' => $this->_oConfig->getCommonPostPrefix() . $sPostType,
                   'action' => '',
                   'content' => process_db_input($aResult['content'], BX_TAGS_NO_ACTION, BX_SLASHES_NO_ACTION),
                   'title' => process_db_input($aResult['title'], BX_TAGS_NO_ACTION, BX_SLASHES_NO_ACTION),
                   'description' => process_db_input($aResult['description'], BX_TAGS_NO_ACTION, BX_SLASHES_NO_ACTION)
                ));

                //--- Event -> Post for Alerts Engine ---//
                bx_import('BxDolAlerts');
                $oAlert = new BxDolAlerts($this->_oConfig->getAlertSystemName(), 'post', $iId, $this->_getAuthorId());
                $oAlert->alert();
                //--- Event -> Post for Alerts Engine ---//

                $sResult = "parent.$('form#WallPost" . ucfirst($sPostType) . "').find(':input:not(:button,:submit,[type = hidden],[type = radio],[type = checkbox])').val('');\n";
                $sResult .= "parent." . $this->_sJsPostObject . "._getPost(null, " . $iId . ");";
            } else
                $sResult .= "alert('" . bx_js_string(_t($aResult['message'])) . "');";
        } else
           $sResult .= "alert('" . bx_js_string(_t('_wall_msg_failed_post')) . "');";

        return '<script>' . $sResult . '</script>';
    }
    /**
     * Delete post from the wall. Allow to wall owner only.
     *
     * @return string with JavaScript code.
     */
    function actionDelete()
    {
        $oJson = new Services_JSON();
        header('Content-Type:text/javascript');

        $this->_iOwnerId = (int)$_POST['WallOwnerId'];
        if(!$this->_isCommentDeleteAllowed(true))
            return $oJson->encode(array('code' => 1));

        $iEventId = (int)$_POST['WallEventId'];
        $bResult = $this->_oDb->deleteEvent(array('id' => $iEventId));

        if($bResult) {
            //--- Event -> Delete for Alerts Engine ---//
            bx_import('BxDolAlerts');
            $oAlert = new BxDolAlerts($this->_oConfig->getAlertSystemName(), 'delete', $iEventId, $this->_getAuthorId());
            $oAlert->alert();
            //--- Event -> Delete for Alerts Engine ---//

            return $oJson->encode(array('code' => 0, 'id' => $iEventId));
        } else
            return $oJson->encode(array('code' => 2));
    }
    /**
     * Get post content.
     *
     * @return string with post.
     */
    function actionGetPost()
    {
        $this->_oConfig->setJsMode(true);
        $this->_iOwnerId = (int)$_POST['WallOwnerId'];
        $iPostId = (int)$_POST['WallPostId'];

        $aEvents = $this->_oDb->getEvents(array('type' => 'id', 'object_id' => $iPostId));

        header('Content-Type: text/html; charset=utf-8');
        return $this->_oTemplate->getCommon($aEvents[0]);
    }
    /**
     * Get posts content.
     *
     * @return string with posts.
     */
    function actionGetPosts()
    {
        $this->_oConfig->setJsMode(true);

        $this->_iOwnerId = $_POST['WallOwnerId'];
        if(strpos($this->_iOwnerId, BX_WALL_DIVIDER_ID) !== false)
            $this->_iOwnerId = explode(BX_WALL_DIVIDER_ID, $this->_iOwnerId);

        $iStart = (int)$_POST['WallStart'];
        $iPerPage = isset($_POST['WallPerPage']) ? (int)$_POST['WallPerPage'] : $this->_oConfig->getPerPage();
        $sFilter = isset($_POST['WallFilter']) ? process_db_input($_POST['WallFilter'], BX_TAGS_STRIP) : BX_WALL_FILTER_ALL;
        $sTimeline = isset($_POST['WallTimeline']) ? process_db_input($_POST['WallTimeline'], BX_TAGS_STRIP) : '';

        header('Content-Type: text/html; charset=utf-8');
        return $sContent = $this->_getPosts('desc', $iStart, $iPerPage, $sFilter, $sTimeline);
    }
    /**
     * Get posts content (Outline).
     *
     * @return string with posts.
     */
    function actionGetPostsOutline()
    {
        $this->_oConfig->setJsMode(true);

        $iStart = (int)$_POST['WallStart'];
        $iPerPage = isset($_POST['WallPerPage']) ? (int)$_POST['WallPerPage'] : $this->_oConfig->getPerPage();
        $sFilter = isset($_POST['WallFilter']) ? process_db_input($_POST['WallFilter'], BX_TAGS_STRIP) : BX_WALL_FILTER_ALL;
        list($sContent, $sPaginate) = $this->_getPostsOutline('desc', $iStart, $iPerPage, $sFilter);

        $oJson = new Services_JSON();
        header('Content-Type:text/javascript');
        return $oJson->encode(array(
            'code' => 0,
            'items' => $sContent,
            'paginate' => $sPaginate
        ));
    }
    /**
     * Get timeline content.
     *
     * @return string with paginate.
     */
    function actionGetTimeline()
    {
        $this->_iOwnerId = $_POST['WallOwnerId'];
        if(strpos($this->_iOwnerId, BX_WALL_DIVIDER_ID) !== false)
            $this->_iOwnerId = explode(BX_WALL_DIVIDER_ID, $this->_iOwnerId);

        $iStart = (int)$_POST['WallStart'];
        $iPerPage = isset($_POST['WallPerPage']) ? (int)$_POST['WallPerPage'] : $this->_oConfig->getPerPage();
        $sFilter = isset($_POST['WallFilter']) ? process_db_input($_POST['WallFilter'], BX_TAGS_STRIP) : BX_WALL_FILTER_ALL;
        $sTimeline = isset($_POST['WallTimeline']) ? process_db_input($_POST['WallTimeline'], BX_TAGS_STRIP) : '';

        header('Content-Type: text/html; charset=utf-8');
        return $this->_getTimeline($iStart, $iPerPage, $sFilter, $sTimeline);
    }
    /**
     * Get paginate content.
     *
     * @return string with paginate.
     */
    function actionGetPaginate()
    {
        $this->_iOwnerId = $_POST['WallOwnerId'];
        if(strpos($this->_iOwnerId, BX_WALL_DIVIDER_ID) !== false)
            $this->_iOwnerId = explode(BX_WALL_DIVIDER_ID, $this->_iOwnerId);

        $iStart = (int)$_POST['WallStart'];
        $iPerPage = isset($_POST['WallPerPage']) ? (int)$_POST['WallPerPage'] : $this->_oConfig->getPerPage();
        $sFilter = isset($_POST['WallFilter']) ? process_db_input($_POST['WallFilter'], BX_TAGS_STRIP) : BX_WALL_FILTER_ALL;
        $sTimeline = isset($_POST['WallTimeline']) ? process_db_input($_POST['WallTimeline'], BX_TAGS_STRIP) : '';

        $oPaginate = $this->_getPaginate($sFilter, $sTimeline);

        header('Content-Type: text/html; charset=utf-8');
        return $oPaginate->getPaginate($iStart, $iPerPage);
    }
    /**
     * Get photo uploading form.
     *
     * @return string with form.
     */
    function actionGetPhotoUploaders($iOwnerId)
    {
        $this->_iOwnerId = $iOwnerId;
        header('Content-Type: text/html; charset=utf-8');
        return BxDolService::call('photos', 'get_uploader_form', array(array('mode' => 'single', 'category' => 'wall', 'album'=>_t('_wall_photo_album', getNickName(getLoggedId())), 'from_wall' => 1, 'owner_id' => $this->_iOwnerId)), 'Uploader');
    }
    /**
     * Get music uploading form.
     *
     * @return srting with form.
     */
    function actionGetMusicUploaders($iOwnerId)
    {
        $this->_iOwnerId = $iOwnerId;
        header('Content-Type: text/html; charset=utf-8');
        return BxDolService::call('sounds', 'get_uploader_form', array(array('mode' => 'single', 'category' => 'wall', 'album'=>_t('_wall_sound_album', getNickName(getLoggedId())), 'from_wall' => 1, 'owner_id' => $this->_iOwnerId)), 'Uploader');
    }
    /**
     * Get video uploading form.
     *
     * @return string with form.
     */
    function actionGetVideoUploaders($iOwnerId)
    {
        $this->_iOwnerId = $iOwnerId;
        header('Content-Type: text/html; charset=utf-8');
        return BxDolService::call('videos', 'get_uploader_form', array(array('mode' => 'single', 'category' => 'wall', 'album'=>_t('_wall_video_album', getNickName(getLoggedId())), 'from_wall' => 1, 'owner_id' => $this->_iOwnerId)), 'Uploader');
    }
    /**
     * Get RSS for specified owner.
     *
     * @param  string $sUsername wall owner username
     * @return string with RSS.
     */
    function actionRss($sUsername)
    {
        $aOwner = $this->_oDb->getUser($sUsername, 'username');

        $aEvents = $this->_oDb->getEvents(array(
            'type' => 'owner',
            'owner_id' => $aOwner['id'],
            'order' => 'desc',
            'start' => 0,
            'count' => $this->_oConfig->getRssLength(),
            'filter' => ''
        ));

        $sRssBaseUrl = $this->_oConfig->getBaseUri() . 'index/' . $aOwner['username'] . '/';
        $aRssData = array();
        foreach($aEvents as $aEvent) {
            if(empty($aEvent['title'])) continue;

            $aRssData[$aEvent['id']] = array(
               'UnitID' => $aEvent['id'],
               'OwnerID' => $aOwner['id'],
               'UnitTitle' => $aEvent['title'],
               'UnitLink' => BX_DOL_URL_ROOT . $sRssBaseUrl . '#wall-event-' . $aEvent['id'],
               'UnitDesc' => $aEvent['description'],
               'UnitDateTimeUTS' => $aEvent['date'],
               'UnitIcon' => ''
            );
        }

        $oRss = new BxDolRssFactory();

        header('Content-Type: text/html; charset=utf-8');
        return $oRss->GenRssByData($aRssData, $aOwner['username'] . ' ' . _t('_wall_rss_caption'), $sRssBaseUrl);
    }

    /**
     * SERVICE METHODS
     * Process alert.
     *
     * @param BxDolAlerts $oAlert an instance with accured alert.
     */
    function serviceResponse($oAlert)
    {
        $oResponse = new BxWallResponse($this);
        $oResponse->response($oAlert);
    }
    /**
     * Display Post block on profile page.
     *
     * @param  integer $mixed - owner ID or Username.
     * @return array   containing block info.
     */
    function servicePostBlock($mixed, $sType = 'id')
    {
        $aOwner = $this->_oDb->getUser($mixed, $sType);
        $this->_iOwnerId = $aOwner['id'];

        if($aOwner['id'] != $this->_getAuthorId() && !$this->_isCommentPostAllowed())
            return "";

        $aTopMenu = array(
            'wall-ptype-text' => array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsPostObject . '.changePostType(this)', 'class' => 'wall-ptype-ctl', 'icon' => 'comment-alt', 'title' => _t('_wall_write'), 'active' => 1),
            'wall-ptype-link' => array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsPostObject . '.changePostType(this)', 'class' => 'wall-ptype-ctl', 'icon' => 'link', 'title' => _t('_wall_share_link')),
        );

        if($this->_oDb->isModule('photos'))
            $aTopMenu['wall-ptype-photo'] = array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsPostObject . '.changePostType(this)', 'class' => 'wall-ptype-ctl', 'icon' => 'picture', 'title' => _t('_wall_add_photo'));
        if($this->_oDb->isModule('sounds'))
            $aTopMenu['wall-ptype-music'] = array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsPostObject . '.changePostType(this)', 'class' => 'wall-ptype-ctl', 'icon' => 'music', 'title' => _t('_wall_add_music'));
        if($this->_oDb->isModule('videos'))
            $aTopMenu['wall-ptype-video'] = array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsPostObject . '.changePostType(this)', 'class' => 'wall-ptype-ctl', 'icon' => 'film', 'title' => _t('_wall_add_video'));

        //--- Prepare JavaScript paramaters ---//
        ob_start();
?>
        var <?=$this->_sJsPostObject; ?> = new BxWallPost({
            sActionUrl: '<?=BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri(); ?>',
            sObjName: '<?=$this->_sJsPostObject; ?>',
            iOwnerId: <?=$this->_iOwnerId; ?>,
            sAnimationEffect: '<?=$this->_oConfig->getAnimationEffect(); ?>',
            iAnimationSpeed: '<?=$this->_oConfig->getAnimationSpeed(); ?>'
        });
<?php
        $sJsContent = ob_get_clean();

        //--- Parse template ---//
        $aVariables = array (
            'post_js_content' => $sJsContent,
            'post_js_object' => $this->_sJsPostObject,

            'post_wall_text' => $this->_getWriteForm(),
            'post_wall_link' => $this->_getShareLinkForm(),
            'post_wall_photo' => '',
            'post_wall_video' => '',
            'post_wall_music' => '',
        );

        $GLOBALS['oTopMenu']->setCurrentProfileID((int)$this->_iOwnerId);

        $this->_oTemplate->addCss('post.css');
        $this->_oTemplate->addJs(array('main.js', 'post.js'));
        return array($this->_oTemplate->parseHtmlByName('post.html', $aVariables), $aTopMenu, LoadingBox('bx-wall-post-loading'), true, 'getBlockCaptionMenu');
    }
    function serviceViewBlock($mixed, $iStart = -1, $iPerPage = -1, $sFilter = '', $sTimeline = '', $sType = 'id')
    {
        $sContent = '';

        $aOwner = $this->_oDb->getUser($mixed, $sType);
        $this->_iOwnerId = $aOwner['id'];

        $oSubscription = new BxDolSubscription();
        $aButton = $oSubscription->getButton($this->getUserId(), 'bx_wall', '', $this->_iOwnerId);

        $aTopMenu = array(
            'wall-view-all' => array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsViewObject . '.changeFilter(this)', 'title' => _t('_wall_view_all'), 'active' => 1),
            'wall-view-owner' => array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsViewObject . '.changeFilter(this)', 'title' => _t('_wall_view_owner', getNickName($aOwner['id']))),
            'wall-view-other' => array('href' => 'javascript:void(0)', 'onclick' => 'javascript:' . $this->_sJsViewObject . '.changeFilter(this)', 'title' => _t('_wall_view_other')),
            'wall-get-rss' => array('href' => BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri() . 'rss/' . $aOwner['username'] . '/', 'target' => '_blank', 'title' => _t('_wall_get_rss')),
            'wall-subscription' => array('href' => 'javascript:void(0);', 'onclick' => 'javascript:' . $aButton['script'] . '', 'title' => $aButton['title']),
        );

        if($iStart == -1)
           $iStart = 0;
        if($iPerPage == -1)
           $iPerPage = $this->_oConfig->getPerPage();
        if(empty($sFilter))
            $sFilter = BX_WALL_FILTER_ALL;

        //--- Prepare JavaScript paramaters ---//
        ob_start();
?>
        var <?=$this->_sJsViewObject; ?> = new BxWallView({
            sActionUrl: '<?=BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri(); ?>',
            sObjName: '<?=$this->_sJsViewObject; ?>',
            iOwnerId: <?=$this->_iOwnerId; ?>,
            sAnimationEffect: '<?=$this->_oConfig->getAnimationEffect(); ?>',
            iAnimationSpeed: '<?=$this->_oConfig->getAnimationSpeed(); ?>'
        });
<?php
        $sJsContent = ob_get_clean();

        //--- Is used with common Pagination
        //$oPaginate = $this->_getPaginate($sFilter);

        $aVariables = array(
            'timeline' => $this->_getTimeline($iStart, $iPerPage, $sFilter, $sTimeline),
            'content' => $this->_getPosts('desc', $iStart, $iPerPage, $sFilter, $sTimeline),
            'view_js_content' => $sJsContent,
            //--- Is used with common Pagination
            //'paginate' => $oPaginate->getPaginate()
        );

        $GLOBALS['oTopMenu']->setCurrentProfileID((int)$this->_iOwnerId);

        $this->_oTemplate->addCss(array('forms_adv.css', 'view.css'));
        $this->_oTemplate->addJs(array('main.js', 'view.js'));
        return array($this->_oTemplate->parseHtmlByName('view.html', $aVariables), $aTopMenu, LoadingBox('bx-wall-view-loading'), false, 'getBlockCaptionMenu');
    }

    function serviceViewBlockAccount($mixed, $iStart = -1, $iPerPage = -1, $sFilter = '', $sTimeline = '', $sType = 'id')
    {
        $sContent = '';

        $aOwner = $this->_oDb->getUser($mixed, $sType);
        $this->_iOwnerId = $aOwner['id'];

        $aFriends = getMyFriendsEx($this->_iOwnerId, '', '', 'LIMIT 20');
        if(empty($aFriends))
            return $this->_oTemplate->getEmpty(true);

        $this->_iOwnerId = array_keys($aFriends);

        if($iStart == -1)
           $iStart = 0;
        if($iPerPage == -1)
           $iPerPage = $this->_oConfig->getPerPage('account');
        if(empty($sFilter))
            $sFilter = BX_WALL_FILTER_ALL;

        //--- Prepare JavaScript paramaters ---//
        ob_start();
?>
        var <?=$this->_sJsViewObject; ?> = new BxWallView({
            sActionUrl: '<?=BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri(); ?>',
            sObjName: '<?=$this->_sJsViewObject; ?>',
            iOwnerId: '<?=implode(BX_WALL_DIVIDER_ID, $this->_iOwnerId); ?>',
            sAnimationEffect: '<?=$this->_oConfig->getAnimationEffect(); ?>',
            iAnimationSpeed: '<?=$this->_oConfig->getAnimationSpeed(); ?>'
        });
<?php
        $sJsContent = ob_get_clean();

        //--- Is used with common Pagination
        //$oPaginate = $this->_getPaginate($sFilter);

        $aVariables = array(
            'timeline' => $this->_getTimeline($iStart, $iPerPage, $sFilter, $sTimeline),
            'content' => $this->_getPosts('desc', $iStart, $iPerPage, $sFilter, $sTimeline),
            'view_js_content' => $sJsContent,
            //--- Is used with common Pagination
            //'paginate' => $oPaginate->getPaginate()
        );

        bx_import('BxTemplFormView');
		$oForm = new BxTemplFormView(array());
		$oForm->addCssJs(true, true);

        $this->_oTemplate->addCss(array('forms_adv.css', 'view.css'));
        $this->_oTemplate->addJs(array('main.js', 'view.js'));
        return array($this->_oTemplate->parseHtmlByName('view.html', $aVariables), array(), LoadingBox('bx-wall-view-loading'), false, 'getBlockCaptionMenu');
    }

    function serviceViewBlockIndex($iStart = -1, $iPerPage = -1, $sFilter = '')
    {
        $sContent = '';
        $this->_iOwnerId = 0;

        if($iStart == -1)
           $iStart = 0;
        if($iPerPage == -1)
           $iPerPage = $this->_oConfig->getPerPage('index');
        if(empty($sFilter))
            $sFilter = BX_WALL_FILTER_ALL;

        //--- Prepare JavaScript paramaters ---//
        ob_start();
?>
        var <?=$this->_sJsOutlineObject; ?> = new BxWallOutline({
            sActionUrl: '<?=BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri(); ?>',
            sObjName: '<?=$this->_sJsOutlineObject; ?>',
            iOwnerId: '0',
            sAnimationEffect: '<?=$this->_oConfig->getAnimationEffect(); ?>',
            iAnimationSpeed: '<?=$this->_oConfig->getAnimationSpeed(); ?>'
        });
<?php
        $sJsContent = ob_get_clean();

        list($sContent, $sPaginate) = $this->_getPostsOutline('desc', $iStart, $iPerPage, $sFilter);
        if(empty($sContent))
            return;

        $aTmplVars = array(
            'outline_js_content' => $sJsContent,
            'content' => $sContent,
            'paginate' => $sPaginate
        );

        $this->_oTemplate->addCss(array('outline.css'));
        $this->_oTemplate->addJs(array('jquery.masonry.min.js', 'main.js', 'outline.js'));
        return array($this->_oTemplate->parseHtmlByName('outline.html', $aTmplVars), array(), LoadingBox('bx-wall-view-loading'), true, 'getBlockCaptionMenu');
    }

    function serviceGetActionsChecklist($sType)
    {
        bx_instance('BxDolModuleDb');
        $oModuleDb = new BxDolModuleDb();
        $aHandlers = $this->_oDb->getHandlers(array('type' => $sType));

        $aResults = array();
        foreach($aHandlers as $aHandler) {
            $aModule = $oModuleDb->getModuleByUri($aHandler['module_uri']);
            if(empty($aModule))
                $aModule['title'] = _t('_wall_alert_module_' . $aHandler['alert_unit']);

            $aResults[$aHandler['id']] = $aModule['title'] . ' (' . _t('_wall_alert_action_' . $aHandler['alert_action']) . ')';
        }

        asort($aResults);
        return $aResults;
    }

    function serviceUpdateHandlers($sModuleUri = 'all', $bInstall = true)
    {
        $aModules = $sModuleUri == 'all' ? $this->_oDb->getModules() : array($this->_oDb->getModuleByUri($sModuleUri));

        foreach($aModules as $aModule) {
           if(!BxDolRequest::serviceExists($aModule, 'get_wall_data')) continue;

           $aData = BxDolService::call($aModule['uri'], 'get_wall_data');
           if($bInstall)
               $this->_oDb->insertData($aData);
           else
               $this->_oDb->deleteData($aData);
        }

        BxDolAlerts::cache();
    }

    function serviceGetMemberMenuItem()
    {
        $oMemberMenu = bx_instance('BxDolMemberMenu');

        $aLanguageKeys = array(
            'wall' => _t( '_wall_pc_view' ),
        );

        // fill all necessary data;
        $aLinkInfo = array(
            'item_img_src'  => 'time',
            'item_img_alt'  => $aLanguageKeys['wall'],
            'item_link'     => BX_DOL_URL_ROOT . $this -> _oConfig -> getBaseUri(),
            'item_onclick'  => null,
            'item_title'    => $aLanguageKeys['wall'],
            'extra_info'    => null,
        );

        return $oMemberMenu -> getGetExtraMenuLink($aLinkInfo);
    }
    function serviceGetSubscriptionParams($sUnit, $sAction, $iObjectId)
    {
        $sUnit = str_replace('bx_', '_', $sUnit);
        if(empty($sAction))
            $sAction = 'main';

        $aProfileInfo = getProfileInfo($iObjectId);
        return array(
            'template' => array(
                'Subscription' => _t($sUnit . '_sbs_' . $sAction, $aProfileInfo['NickName']),
                'ViewLink' => BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri()  . 'index/' . $aProfileInfo['NickName']
            )
        );
    }

    function serviceGetSpyData()
    {
        $AlertName = $this->_oConfig->getAlertSystemName();

        return array(
            'handlers' => array(
                array('alert_unit' => $AlertName, 'alert_action' => 'post', 'module_uri' => $this->_oConfig->getUri(), 'module_class' => 'Module', 'module_method' => 'get_spy_post'),
                array('alert_unit' => $AlertName, 'alert_action' => 'commentPost', 'module_uri' => $this->_oConfig->getUri(), 'module_class' => 'Module', 'module_method' => 'get_spy_post'),
            ),
            'alerts' => array(
                array('unit' => $AlertName, 'action' => 'post'),
                array('unit' => $AlertName, 'action' => 'delete'),
                array('unit' => $AlertName, 'action' => 'commentPost'),
                array('unit' => $AlertName, 'action' => 'commentRemoved')
            )
        );
    }

    function serviceGetSpyPost($sAction, $iObjectId = 0, $iSenderId = 0, $aExtraParams = array())
    {
        $aEvent = $this->_oDb->getEvents(array('type' => 'id', 'object_id' => $iObjectId));
        $aEvent = array_shift($aEvent);

        $sLangKey = '';
        switch ($sAction) {
            case 'post':
                $sLangKey = '_wall_spy_post';
                break;
            case 'commentPost':
                $sLangKey = '_wall_spy_post_comment';
                break;
        }

        return array(
            'params'    => array(
                'profile_link'  => getProfileLink($iSenderId),
                'profile_nick'  => getNickName($iSenderId),
                'recipient_p_link' => getProfileLink($aEvent['owner_id']),
                'recipient_p_nick' => getNickName($aEvent['owner_id']),
            ),
            'recipient_id' => $aEvent['owner_id'],
            'lang_key' => $sLangKey
        );
    }

    /**
     * Private Methods
     * Is used for actions processing.
     */
    function _processTextUpload()
    {
        $aOwner = $this->_oDb->getUser($this->_getAuthorId());

        $sContent = get_magic_quotes_gpc() ? stripslashes($_POST['content']) : $_POST['content'];
        $sContent = nl2br(strip_tags($sContent));

        if(empty($sContent))
            return array(
                'code' => 1,
                'message' => '_wall_msg_text_empty_message'
            );

        return array(
            'code' => 0,
            'object_id' => $aOwner['id'],
            'content' => $this->_oTemplate->parseHtmlByName('common_text.html', array(
                'content' => $sContent
            )),
            'title' => $aOwner['username'] . ' ' . _t('_wall_wrote'),
            'description' => $sContent
        );
    }
    function _processLinkUpload()
    {
        $aOwner = $this->_oDb->getUser($this->_getAuthorId());

        $sUrl = trim(process_db_input($_POST['url'], BX_TAGS_STRIP));
        if(empty($sUrl))
            return array(
                'code' => 1,
                'message' => '_wall_msg_link_empty_link'
            );

        $sContent = bx_file_get_contents($sUrl);

        preg_match("/<title>(.*)<\/title>/", $sContent, $aMatch);
        $sTitle = $aMatch[1];

        preg_match("/<meta.*name[='\" ]+description['\"].*content[='\" ]+(.*)['\"].*><\/meta>/", $sContent, $aMatch);
        $sDescription = $aMatch[1];

        return array(
           'object_id' => $aOwner['id'],
           'content' => $this->_oTemplate->parseHtmlByName('common_link.html', array(
               'title' => $sTitle,
               'url' => strpos($sUrl, 'http://') === false && strpos($sUrl, 'https://') === false ? 'http://' . $sUrl : $sUrl,
               'description' => $sDescription
           )),
           'title' => $aOwner['username'] . ' ' . _t('_wall_shared_link'),
           'description' => $sUrl . ' - ' . $sTitle
        );
    }

    /**
     * Private Methods
     * Is used for content displaying
     */
    function _getTimeline($iStart, $iPerPage, $sFilter, $sTimeline)
    {
        return $this->_oTemplate->getTimeline($iStart, $iPerPage, $sFilter, $sTimeline);
    }
    function _getLoadMore($iStart, $iPerPage, $sFilter, $sTimeline, $bEnabled = true, $bVisible = true)
    {
        return $this->_oTemplate->getLoadMore($iStart, $iPerPage, $sFilter, $sTimeline, $bEnabled, $bVisible);
    }
    function _getLoadMoreOutline($iStart, $iPerPage, $sFilter, $bEnabled = true, $bVisible = true)
    {
        return $this->_oTemplate->getLoadMoreOutline($iStart, $iPerPage, $sFilter, $bEnabled, $bVisible);
    }
    function _getPaginate($sFilter)
    {
        return new BxDolPaginate(array(
            'page_url' => 'javascript:void(0);',
            'start' => 0,
            'count' => $this->_oDb->getEventsCount($this->_iOwnerId, $sFilter),
            'per_page' => $this->_oConfig->getPerPage(),
            'on_change_page' => $this->_sJsViewObject . '.changePage({start}, {per_page}, \'' . $sFilter . '\')',
            'on_change_per_page' => $this->_sJsViewObject . '.changePerPage(this, \'' . $sFilter . '\')',
            'page_reloader' => true
        ));
    }
    function _getPosts($sOrder, $iStart, $iPerPage, $sFilter, $sTimeline)
    {
        $iDays = -1;
        $bPrevious = $bNext = false;

        $iStartEv = $iStart;
        $iPerPageEv = $iPerPage;

        //--- Check for Previous
        if($iStart - 1 >= 0) {
            $iStartEv -= 1;
            $iPerPageEv += 1;
            $bPrevious = true;
        }

        //--- Check for Next
        $iPerPageEv += 1;

        $aEvents = $this->_oDb->getEvents(array('type' => 'owner', 'owner_id' => $this->_iOwnerId, 'order' => $sOrder, 'start' => $iStartEv, 'count' => $iPerPageEv, 'filter' => $sFilter, 'timeline' => $sTimeline));

        //--- Check for Previous
        if($bPrevious) {
            $aEvent = array_shift($aEvents);
            $iDays = (int)$aEvent['days'];
        }

        //--- Check for Next
        if(count($aEvents) > $iPerPage) {
            $aEvent = array_pop($aEvents);
            $bNext = true;
        }

        $iEvents = count($aEvents);
        $sContent = $this->_oTemplate->getEmpty($iEvents <= 0);

        $bFirst = true;
        foreach($aEvents as $aEvent) {
            $aEvent['content'] = !empty($aEvent['action']) ? $this->_oTemplate->getSystem($aEvent) : $this->_oTemplate->getCommon($aEvent);
            if(empty($aEvent['content']))
                continue;

            if($bFirst) {
                $sContent .= $this->_oTemplate->getDividerToday($aEvent);
                $bFirst = false;
            }

            $sContent .= !empty($aEvent['content']) ? $this->_oTemplate->getDivider($iDays, $aEvent) : '';
            $sContent .= $aEvent['content'];
        }

        $sContent .= $this->_getLoadMore($iStart, $iPerPage, $sFilter, $sTimeline, $bNext, $iEvents > 0);
        return $sContent;
    }
    function _getPostsOutline($sOrder, $iStart, $iPerPage, $sFilter)
    {
        $iStartEv = $iStart;
        $iPerPageEv = $iPerPage;

        //--- Check for Next
        $iPerPageEv += 1;
        $aEvents = $this->_oDb->getEvents(array('type' => 'outline', 'order' => $sOrder, 'start' => $iStartEv, 'count' => $iPerPageEv, 'filter' => $sFilter));

        //--- Check for Next
        $bNext = false;
        if(count($aEvents) > $iPerPage) {
            $aEvent = array_pop($aEvents);
            $bNext = true;
        }

        $iEvents = count($aEvents);
        foreach($aEvents as $aEvent) {
            if(empty($aEvent['action']))
                continue;

            $aEvent['content'] = $this->_oTemplate->getSystem($aEvent, BX_WALL_VIEW_OUTLINE);
            if(empty($aEvent['content']))
                continue;

            $sContent .= $aEvent['content'];
        }

        $sPaginate = $this->_getLoadMoreOutline($iStart, $iPerPage, $sFilter, $bNext, $iEvents > 0);
        return array($sContent, $sPaginate);
    }
    function _getWriteForm()
    {
        $aForm = array(
            'form_attrs' => array(
                'name' => 'WallPostText',
                'action' => BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri() . 'post/',
                'method' => 'post',
                'enctype' => 'multipart/form-data',
                'target' => 'WallPostIframe',
                'onsubmit' => 'javascript:return ' . $this->_sJsPostObject . '.postSubmit(this);'
            ),
            'inputs' => array(
                'content' => array(
                    'type' => 'textarea',
                    'name' => 'content',
                    'caption' => '',
                    'colspan' => true
                ),
                'submit' => array(
                    'type' => 'submit',
                    'name' => 'submit',
                    'value' => _t('_wall_post'),
                    'colspan' => true
                )
            ),
        );
        $aForm['inputs'] = array_merge($aForm['inputs'], $this->_addHidden('text'));

        $oForm = new BxTemplFormView($aForm);
        return $oForm->getCode();
    }
    function _getShareLinkForm()
    {
        $aForm = array(
            'form_attrs' => array(
                'name' => 'WallPostLink',
                'action' => BX_DOL_URL_ROOT . $this->_oConfig->getBaseUri() . 'post/',
                'method' => 'post',
                'enctype' => 'multipart/form-data',
                'target' => 'WallPostIframe',
                'onsubmit' => 'javascript:return ' . $this->_sJsPostObject . '.postSubmit(this);'
            ),
            'inputs' => array(
                'title' => array(
                    'type' => 'text',
                    'name' => 'url',
                    'caption' => _t('_wall_link_url'),
                ),
                'submit' => array(
                    'type' => 'submit',
                    'name' => 'submit',
                    'value' => _t('_wall_post'),
                    'colspan' => true
                )
            ),
        );
        $aForm['inputs'] = array_merge($aForm['inputs'], $this->_addHidden('link'));

        $oForm = new BxTemplFormView($aForm);
        return $oForm->getCode();
    }
    function _addHidden($sPostType = "photos", $sContentType = "upload", $sAction = "post")
    {
        return array(
            'WallOwnerId' => array (
                'type' => 'hidden',
                'name' => 'WallOwnerId',
                'value' => $this->_iOwnerId,
            ),
            'WallPostAction' => array (
                'type' => 'hidden',
                'name' => 'WallPostAction',
                'value' => $sAction,
            ),
            'WallPostType' => array (
                'type' => 'hidden',
                'name' => 'WallPostType',
                'value' => $sPostType,
            ),
            'WallContentType' => array (
                'type' => 'hidden',
                'name' => 'WallContentType',
                'value' => $sContentType,
            ),
        );
    }
    function _isCommentPostAllowed($bPerform = false)
    {
        if(isAdmin())
            return true;

        $iAuthorId = $this->_getAuthorId();
        if($iAuthorId == 0 && getParam('wall_enable_guest_comments') == 'on')
               return true;

           if(isBlocked($this->_iOwnerId, $iAuthorId))
            return false;

        $aCheckResult = checkAction($iAuthorId, ACTION_ID_TIMELINE_POST_COMMENT, $bPerform);
        return $aCheckResult[CHECK_ACTION_RESULT] == CHECK_ACTION_RESULT_ALLOWED;
    }
    function _isCommentDeleteAllowed($bPerform = false)
    {
        if(isAdmin())
            return true;

        $iUserId = (int)$this->_getAuthorId();
        if($this->_iOwnerId == $iUserId)
           return true;

        $aCheckResult = checkAction($iUserId, ACTION_ID_TIMELINE_DELETE_COMMENT, $bPerform);
        return $aCheckResult[CHECK_ACTION_RESULT] == CHECK_ACTION_RESULT_ALLOWED;
    }
    function _getAuthorId()
    {
        return !isLogged() ? 0 : getLoggedId();
    }
    function _getAuthorPassword()
    {
        return !isLogged() ? '' : getLoggedPassword();
    }
}
