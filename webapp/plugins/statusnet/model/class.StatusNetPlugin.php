<?php
/**
 *
 * ThinkUp/webapp/plugins/statusnet/model/class.StatusNetPlugin.php
 *
 * Copyright (c) 2009-2012 Gina Trapani
 *
 * LICENSE:
 *
 * This file is part of ThinkUp (http://thinkupapp.com).
 *
 * ThinkUp is free software: you can redistribute it and/or modify it under the terms of the GNU General Public
 * License as published by the Free Software Foundation, either version 2 of the License, or (at your option) any
 * later version.
 *
 * ThinkUp is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
 * warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more
 * details.
 *
 * You should have received a copy of the GNU General Public License along with ThinkUp.  If not, see
 * <http://www.gnu.org/licenses/>.
 *
 *
 * StatusNet Plugin
 *
 * StatusNet crawler and webapp plugin retrieves data from StatusNet and displays it.
 *
 * @license http://www.gnu.org/licenses/gpl.html
 * @copyright 2009-2012 Gina Trapani
 * @author Gina Trapani <ginatrapani[at]gmail[dot]com>
 *
 */
class StatusNetPlugin extends Plugin implements CrawlerPlugin, DashboardPlugin, PostDetailPlugin {

    /**
     * Percentage of allocated API calls that each crawler function will use per run for non-authed instances.
     * @var array
     */
    var $api_budget_allocation_noauth = array(
        'fetchInstanceUserTweets' => array('percent' => 25),
        'fetchAndAddTweetRepliedTo' => array('percent' => 25), // for fetchStrayRepliedToTweets
        'fetchAndAddUser' => array('percent' => 25),
        'fetchFriendTweetsAndFriends' => array('percent' => 25),
        'fetchSearchResults' => array('percent' => 25),
        'cleanUpFollows' => array('percent' => 25)
    );

    /**
     * Percentage of allocated API calls that each crawler function will use per run for authed instances.
     * @var array
     */
    var $api_budget_allocation_auth = array(
        'fetchInstanceUserTweets' => array('percent' => 20),
        'fetchAndAddTweetRepliedTo' => array('percent' => 20), // for fetchStrayRepliedToTweets
        'fetchAndAddUser' => array('percent' => 20), // for fetchUnloadedFollowerDetails
        'fetchFriendTweetsAndFriends' => array('percent' => 20),
        'fetchInstanceUserMentions' => array('percent' => 20),
        'fetchInstanceUserFriends' => array('percent' => 20),
        'getFavsPage' => array('percent' => 20), // called from testCleanupMissedFavs|maintFavsFetch|archivingFavsFetch
        'archivingFavsFetch' => array('percent' => 20), // called from fetchInstanceFavorites
        'fetchInstanceUserFollowersByIDs' => array('percent' => 20), // for fetchInstanceUserFollowers
        'fetchUserTimelineForRetweet' => array('percent' => 20), // fetchRetweetsOfInstanceUser->fetchStatusRetweets
        'cleanUpMissedFavsUnFavs' => array('percent' => 20),
        'cleanUpFollows' => array('percent' => 20),
        'fetchInstanceUserGroups'  => array('percent' => 20),
        'updateStaleGroupMemberships'  => array('percent' => 20),
    );

    public function __construct($vals = null) {
        parent::__construct($vals);
        $this->folder_name = 'statusnet';
        $this->addRequiredSetting('oauth_consumer_key');
        $this->addRequiredSetting('oauth_consumer_secret');
    }

    public function activate() {
    }

    public function deactivate() {
        //Pause all active StatusNet instances
        $instance_dao = DAOFactory::getDAO('InstanceDAO');
        $statusnet_instances = $instance_dao->getAllInstances("DESC", true, "statusnet");
        foreach ($statusnet_instances as $ti) {
            $instance_dao->setActive($ti->id, false);
        }
    }

    public function crawl() {
        $config = Config::getInstance();
        $logger = Logger::getInstance();
        $instance_dao = DAOFactory::getDAO('StatusNetInstanceDAO');
        $owner_instance_dao = DAOFactory::getDAO('OwnerInstanceDAO');
        $owner_dao = DAOFactory::getDAO('OwnerDAO');

        // get oauth values
        $plugin_option_dao = DAOFactory::GetDAO('PluginOptionDAO');
        $options = $plugin_option_dao->getOptionsHash('statusnet', true);

        $current_owner = $owner_dao->getByEmail(Session::getLoggedInUser());

        $instances = $instance_dao->getAllActiveInstancesStalestFirstByNetwork('statusnet');
        foreach ($instances as $instance) {
            if (!$owner_instance_dao->doesOwnerHaveAccessToInstance($current_owner, $instance)) {
                // Owner doesn't have access to this instance; let's not crawl it.
                continue;
            }
            $logger->setUsername($instance->network_username);
            $logger->logUserSuccess("Starting to collect data for ".$instance->network_username." on StatusNet.",
            __METHOD__.','.__LINE__);
            $tokens = $owner_instance_dao->getOAuthTokens($instance->id);
            $noauth = true;
            $num_statusnet_errors =
            isset($options['num_statusnet_errors']) ? $options['num_statusnet_errors']->option_value : null;
            $max_api_calls_per_crawl =
            isset($options['max_api_calls_per_crawl']) ? $options['max_api_calls_per_crawl']->option_value : 350;
            if (isset($tokens['oauth_access_token']) && $tokens['oauth_access_token'] != ''
            && isset($tokens['oauth_access_token_secret']) && $tokens['oauth_access_token_secret'] != '') {
                $noauth = false;
            }
            $api_calls_to_leave_unmade_per_minute =  isset($options['api_calls_to_leave_unmade_per_minute']) ?
            $options['api_calls_to_leave_unmade_per_minute']->option_value : 2.0;

            if ($noauth) {
                $api = new CrawlerStatusNetAPIAccessorOAuth('NOAUTH', 'NOAUTH',
                $options['oauth_consumer_key']->option_value,
                $options['oauth_consumer_secret']->option_value,
                $api_calls_to_leave_unmade_per_minute, $options['archive_limit']->option_value,
                $num_statusnet_errors, $max_api_calls_per_crawl);
            } else {
                $api = new CrawlerStatusNetAPIAccessorOAuth($tokens['oauth_access_token'],
                $tokens['oauth_access_token_secret'], $options['oauth_consumer_key']->option_value,
                $options['oauth_consumer_secret']->option_value,
                $api_calls_to_leave_unmade_per_minute, $options['archive_limit']->option_value,
                $num_statusnet_errors, $max_api_calls_per_crawl);
            }

            // budget our statusnet calls
            $call_limits = $this->budgetCrawlLimits($max_api_calls_per_crawl, $noauth);

            $crawler = new StatusNetCrawler($instance, $api);

            $api->init();
            $api->setCallerLimits($call_limits);

            if ($api->available_api_calls_for_crawler > 0) {

                $instance_dao->updateLastRun($instance->id);

                // No auth for public StatusNet users
                $crawler->fetchInstanceUserTweets();

                if (!$noauth) {
                    // Auth req'd, for calling user only
                    $crawler->fetchInstanceUserMentions();
                    $crawler->fetchInstanceUserFriends();
                    $crawler->fetchInstanceFavorites();
                    $crawler->fetchInstanceUserFollowers();
                    $crawler->fetchInstanceUserGroups();
                    $crawler->fetchRetweetsOfInstanceUser();
                    $crawler->cleanUpMissedFavsUnFavs();
                    $crawler->updateStaleGroupMemberships();
                }

                $crawler->fetchStrayRepliedToTweets();
                $crawler->fetchUnloadedFollowerDetails();
                $crawler->fetchFriendTweetsAndFriends();

                if ($noauth) {
                    // No auth req'd
                    $crawler->fetchSearchResults($instance->network_username);
                }

                $crawler->cleanUpFollows();

                // Save instance
                if (isset($crawler->user)) {
                    $instance_dao->save($instance, $crawler->user->post_count, $logger);
                }
                $logger->logUserSuccess("Finished collecting data for ".$instance->network_username." on StatusNet.",
                __METHOD__.','.__LINE__);
            }
        }
    }

    public function renderConfiguration($owner) {
        $controller = new StatusNetPluginConfigurationController($owner, 'statusnet');
        return $controller->go();
    }

    public function getDashboardMenuItems($instance) {

        // determine if the StatusNet Realtime plugin is active.
        $rt_plugin_active = false;
        $plugin_dao = DAOFactory::getDAO('PluginDAO');
        $plugin_id = $plugin_dao->getPluginId('statusnetrealtime');
        if (isset($plugin_id)) {
            $rt_plugin_active = $plugin_dao->isPluginActive($plugin_id);
        }

        $menus = array();

        $tweets_data_tpl = Utils::getPluginViewDirectory('statusnet').'tweets.tpl';

        $tweets_menu_item = new MenuItem("Tweets", "Tweets insights", $tweets_data_tpl);

        $tweets_menu_ds_1 = new Dataset("all_tweets", 'PostDAO', "getAllPosts", array($instance->network_user_id,
        'statusnet', 5, "#page_number#"), 'getAllPostsIterator', array($instance->network_user_id, 'statusnet',
        GridController::getMaxRows()) );
        $tweets_menu_ds_1->addHelp('userguide/listings/statusnet/dashboard_tweets-all');
        $tweets_menu_item->addDataset($tweets_menu_ds_1);
        $menus['tweets'] = $tweets_menu_item;

        $tweets_menu_ds_2 = new Dataset("inquiries", 'PostDAO', "getAllQuestionPosts",
        array($instance->network_user_id, 'statusnet', 5, "#page_number#"));
        $tweets_menu_ds_2->addHelp( 'userguide/listings/statusnet/dashboard_tweets-questions');
        $tweets_menu_item->addDataset($tweets_menu_ds_2);

        $tweets_menu_ds_3 = new Dataset("most_replied_to_tweets", 'PostDAO', "getMostRepliedToPosts",
        array($instance->network_user_id, 'statusnet', 5, '#page_number#'));
        $tweets_menu_ds_3->addHelp('userguide/listings/statusnet/dashboard_tweets-mostreplies');
        $tweets_menu_item->addDataset($tweets_menu_ds_3);

        //        $tweets_menu_ds_7 = new Dataset("author_replies", 'PostDAO', "getPostsAuthorHasRepliedTo",
        //        array($instance->network_user_id, 5, 'statusnet', '#page_number#', !Session::isLoggedIn()));
        //        $tweets_menu_ds_7->addHelp('userguide/listings/statusnet/dashboard_tweets-convo');
        //        $tweets_menu_item->addDataset($tweets_menu_ds_7);

        $tweets_menu_ds_4 = new Dataset("most_retweeted", 'PostDAO', "getMostRetweetedPosts",
        array($instance->network_user_id, 'statusnet', 5, '#page_number#'));
        $tweets_menu_ds_4->addHelp('userguide/listings/statusnet/dashboard_tweets-mostretweeted');
        $tweets_menu_item->addDataset($tweets_menu_ds_4);

        $tweets_menu_ds_5 = new Dataset("messages_to_you", 'PostDAO', "getPostsToUser",
        array($instance->network_user_id, $instance->network, 5, '#page_number#', !Session::isLoggedIn()),
        'getPostsToUserIterator', array($instance->network_user_id, $instance->network, GridController::getMaxRows()));
        $tweets_menu_ds_5->addHelp('userguide/listings/statusnet/dashboard_tweets-touser');
        $tweets_menu_item->addDataset($tweets_menu_ds_5);

        $tweets_menu_ds_6 = new Dataset("favorites", 'FavoritePostDAO', "getAllFavoritePosts",
        array($instance->network_user_id, 'statusnet', 5, "#page_number#", !Session::isLoggedIn()),
        'getAllFavoritePostsIterator',
        array($instance->network_user_id, 'statusnet', GridController::getMaxRows()) );
        $tweets_menu_ds_6->addHelp('userguide/listings/statusnet/dashboard_ftweets-all');
        $tweets_menu_item->addDataset($tweets_menu_ds_6);

        $follower_data_tpl = Utils::getPluginViewDirectory('statusnet').'followers.tpl';

        $followers_menu_item = new MenuItem("Followers", "Follower insights", $follower_data_tpl);
        $menus['followers'] = $followers_menu_item;

        $followers_ds1 = new Dataset('leastlikely', 'FollowDAO', "getLeastLikelyFollowers", array(
        $instance->network_user_id, 'statusnet', 13, '#page_number#'));
        $followers_menu_item->addDataset($followers_ds1);

        $followers_ds9 = new Dataset('leastlikelythisweek', 'FollowDAO', "getLeastLikelyFollowersThisWeek", array(
        $instance->network_user_id, 'statusnet', 13, '#page_number#'));
        $followers_menu_item->addDataset($followers_ds9);

        $followers_ds2 = new Dataset("popular", 'FollowDAO', "getMostFollowedFollowers", array(
        $instance->network_user_id, 'statusnet', 13, '#page_number#'));
        $followers_ds2->addHelp('userguide/listings/statusnet/dashboard_followers-mostfollowed');
        $followers_menu_item->addDataset($followers_ds2);

        $followers_ds3 = new Dataset("follower_count_history_by_day", 'FollowerCountDAO', 'getHistory',
        array($instance->network_user_id, 'statusnet', 'DAY', 15));
        $followers_menu_item->addDataset($followers_ds3);

        $followers_ds4 = new Dataset("follower_count_history_by_week", 'FollowerCountDAO', 'getHistory',
        array($instance->network_user_id, 'statusnet', 'WEEK', 15));
        $followers_menu_item->addDataset($followers_ds4);

        $followers_ds5 = new Dataset("follower_count_history_by_month", 'FollowerCountDAO', 'getHistory',
        array($instance->network_user_id, 'statusnet', 'MONTH', 15));
        $followers_ds5->addHelp('userguide/listings/statusnet/dashboard_followers-history');
        $followers_menu_item->addDataset($followers_ds5);

        $followers_ds6 = new Dataset("list_membership_count_history_by_day", 'GroupMembershipCountDAO',
        'getHistory', array($instance->network_user_id, 'statusnet', 'DAY', 15));
        $followers_menu_item->addDataset($followers_ds6);

        $followers_ds7 = new Dataset("list_membership_count_history_by_week", 'GroupMembershipCountDAO',
        'getHistory', array($instance->network_user_id, 'statusnet', 'WEEK', 15));
        $followers_menu_item->addDataset($followers_ds7);

        $followers_ds8 = new Dataset("list_membership_count_history_by_month", 'GroupMembershipCountDAO',
        'getHistory', array($instance->network_user_id, 'statusnet', 'MONTH', 15));
        $followers_ds8->addHelp('userguide/listings/statusnet/dashboard_followers-liststats');
        $followers_menu_item->addDataset($followers_ds8);

        $who_you_follow_data_tpl = Utils::getPluginViewDirectory('statusnet').'who_you_follow.tpl';

        $who_you_follow_menu_item = new MenuItem("Who You Follow", "Friend insights", $who_you_follow_data_tpl);
        $menus['you-follow'] = $who_you_follow_menu_item;

        $you_follow_ds1 = new Dataset('chatterboxes', 'FollowDAO', "getMostActiveFollowees", array(
        $instance->network_user_id, 'statusnet', 13, '#page_number#'));
        $you_follow_ds1->addHelp('userguide/listings/statusnet/dashboard_friends-mostactive');
        $who_you_follow_menu_item->addDataset($you_follow_ds1);

        $you_follow_ds2 = new Dataset("deadbeats", 'FollowDAO', "getLeastActiveFollowees", array(
        $instance->network_user_id, 'statusnet', 13, '#page_number#'));
        $you_follow_ds2->addHelp('userguide/listings/statusnet/dashboard_friends-leastactive');
        $who_you_follow_menu_item->addDataset($you_follow_ds2);

        $you_follow_ds3 = new Dataset("popular", 'FollowDAO', "getMostFollowedFollowees", array(
        $instance->network_user_id, 'statusnet', 13, '#page_number#'));
        $you_follow_ds3->addHelp('userguide/listings/statusnet/dashboard_followers-mostfollowed');
        $who_you_follow_menu_item->addDataset($you_follow_ds3);

        $links_data_tpl = Utils::getPluginViewDirectory('statusnet').'links.tpl';

        $links_menu_item = new MenuItem("Links", "Links insights", $links_data_tpl);
        $menus['links'] = $links_menu_item;

        $links_ds_1 = new Dataset("linksinfaves", 'LinkDAO', "getLinksByFavorites",
        array($instance->network_user_id, 'statusnet', 5, '#page_number#',!Session::isLoggedIn()));
        $links_ds_1->addHelp('userguide/listings/statusnet/dashboard_links-favorites');
        $links_menu_item->addDataset($links_ds_1);

        $links_ds_2 = new Dataset("linksbyfriends", 'LinkDAO', "getLinksByFriends",
        array($instance->network_user_id, 'statusnet', 5, '#page_number#',!Session::isLoggedIn()));
        $links_ds_2->addHelp('userguide/listings/statusnet/dashboard_links-friends');
        $links_menu_item->addDataset($links_ds_2);

        $links_ds_3 = new Dataset("photosbyfriends", 'LinkDAO', "getPhotosByFriends",
        array($instance->network_user_id, 'statusnet', 5, '#page_number#',!Session::isLoggedIn()));
        $links_ds_3->addHelp('userguide/listings/statusnet/dashboard_links-photos');
        $links_menu_item->addDataset($links_ds_3);

        //inner items
        $statusnet_data_tpl = Utils::getPluginViewDirectory('statusnet').'statusnet.inline.view.tpl';

        //All tab
        $all_mi = new MenuItem("Your tweets", "All your tweets", $statusnet_data_tpl, "tweets");
        $all_mi_ds = new Dataset("all_tweets", 'PostDAO', "getAllPosts", array($instance->network_user_id,
        'statusnet', 15, "#page_number#"), 'getAllPostsIterator', array($instance->network_user_id, 'statusnet',
        GridController::getMaxRows()) );
        $all_mi_ds->addHelp('userguide/listings/statusnet/dashboard_tweets-all');
        $all_mi->addDataset($all_mi_ds);
        $menus['tweets-all'] = $all_mi;

        //Questions
        $q_mi = new MenuItem("Inquiries", "Inquiries, or tweets with a question mark in them",
        $statusnet_data_tpl, 'tweets');
        $q_mi_ds = new Dataset("all_tweets", 'PostDAO', "getAllQuestionPosts",
        array($instance->network_user_id, 'statusnet', 15, "#page_number#"));
        $q_mi_ds->addHelp( 'userguide/listings/statusnet/dashboard_tweets-questions');
        $q_mi->addDataset($q_mi_ds);
        $menus['tweets-questions'] = $q_mi;

        // Most replied-to
        $mrt_mi = new MenuItem("Most replied-to", "Tweets with most replies", $statusnet_data_tpl, 'tweets');
        $mrt_mi_ds = new Dataset("most_replied_to_tweets", 'PostDAO', "getMostRepliedToPosts",
        array($instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $mrt_mi_ds->addHelp('userguide/listings/statusnet/dashboard_tweets-mostreplies');
        $mrt_mi->addDataset($mrt_mi_ds);
        $menus['tweets-mostreplies'] = $mrt_mi;

        // Most shared
        $mstab = new MenuItem("Most retweeted", "Most retweeted tweets", $statusnet_data_tpl, 'tweets');
        $mstabds = new Dataset("most_retweeted", 'PostDAO', "getMostRetweetedPosts",
        array($instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $mstabds->addHelp('userguide/listings/statusnet/dashboard_tweets-mostretweeted');
        $mstab->addDataset($mstabds);
        $menus["tweets-mostretweeted"] = $mstab;

        if ($rt_plugin_active) {
            // 'home timeline'
            $tltab = new MenuItem("Timeline", "Your Timeline", $statusnet_data_tpl, 'tweets');
            $tltab2 = new Dataset("home_timeline", 'PostDAO', "getPostsByFriends",
            array($instance->network_user_id, $instance->network, 20, '#page_number#', !Session::isLoggedIn()),
            'getPostsByFriendsIterator', array($instance->network_user_id, 'statusnet', GridController::getMaxRows()));
            $tltab->addDataset($tltab2);
            $menus["home-timeline"] = $tltab;
        }

        // Conversations
        $convotab = new MenuItem("Conversations", "Exchanges between you and other users", $statusnet_data_tpl, 'tweets');
        $convotabds = new Dataset("author_replies", 'PostDAO', "getPostsAuthorHasRepliedTo",
        array($instance->network_user_id, 15, 'statusnet', '#page_number#', !Session::isLoggedIn()));
        $convotabds->addHelp('userguide/listings/statusnet/dashboard_tweets-convo');
        $convotab->addDataset($convotabds);
        $menus["tweets-convo"] = $convotab;

        // Messages to you
        $messagestab = new MenuItem("Tweets to you", "Tweets other users sent you", $statusnet_data_tpl, 'tweets');
        $messagestabds = new Dataset("messages_to_you", 'PostDAO', "getPostsToUser",
        array($instance->network_user_id, $instance->network, 15, '#page_number#', !Session::isLoggedIn()),
        'getPostsToUserIterator', array($instance->network_user_id, $instance->network, GridController::getMaxRows()));
        $messagestabds->addHelp('userguide/listings/statusnet/dashboard_tweets-touser');
        $messagestab->addDataset($messagestabds);
        $menus["tweets-messages"] = $messagestab;

        $fvalltab = new MenuItem("Favorites", "All your favorites", $statusnet_data_tpl, 'tweets');
        $fvalltabds = new Dataset("all_tweets", 'FavoritePostDAO', "getAllFavoritePosts",
        array($instance->network_user_id, 'statusnet', 20, "#page_number#", !Session::isLoggedIn()),
        'getAllFavoritePostsIterator',
        array($instance->network_user_id, 'statusnet', GridController::getMaxRows()) );
        $fvalltabds->addHelp('userguide/listings/statusnet/dashboard_ftweets-all');
        $fvalltab->addDataset($fvalltabds);
        $menus["ftweets-all"] = $fvalltab;
        //Most Active Friends
        $motab = new MenuItem('Chatterboxes', 'People you follow who tweet the most', $statusnet_data_tpl, 'you-follow');
        $motabds = new Dataset('people', 'FollowDAO', "getMostActiveFollowees", array(
        $instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $motabds->addHelp('userguide/listings/statusnet/dashboard_friends-mostactive');
        $motab->addDataset($motabds);
        $menus["friends-mostactive"] = $motab;

        //Least Active Friends
        $latab = new MenuItem('Deadbeats', 'People you follow who tweet the least', $statusnet_data_tpl, 'you-follow');
        $latabds = new Dataset("people", 'FollowDAO', "getLeastActiveFollowees", array(
        $instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $latabds->addHelp('userguide/listings/statusnet/dashboard_friends-leastactive');
        $latab->addDataset($latabds);
        $menus["friends-leastactive"] = $latab;

        //Popular friends
        $poptab = new MenuItem('Popular', 'Most-followed people you follow', $statusnet_data_tpl, 'you-follow');
        $poptabds = new Dataset("people", 'FollowDAO', "getMostFollowedFollowees", array(
        $instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $poptabds->addHelp('userguide/listings/statusnet/dashboard_followers-mostfollowed');
        $poptab->addDataset($poptabds);
        $menus["friends-mostfollowed"] = $poptab;

        //Least likely/Most Discerning
        $lltab = new MenuItem("Discerning", 'Followers with the greatest follower-to-friend ratio', $statusnet_data_tpl,
        'followers');
        $lltabds = new Dataset("people", 'FollowDAO', "getLeastLikelyFollowers", array(
        $instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $lltabds->addHelp('userguide/listings/statusnet/dashboard_followers-leastlikely');
        $lltab->addDataset($lltabds);
        $menus["followers-leastlikely"] = $lltab;

        //Most followed
        $mftab = new MenuItem('Popular', 'Followers with the most followers',
        $statusnet_data_tpl, 'followers');
        $mftabds = new Dataset("people", 'FollowDAO', "getMostFollowedFollowers", array(
        $instance->network_user_id, 'statusnet', 15, '#page_number#'));
        $mftabds->addHelp('userguide/listings/statusnet/dashboard_followers-mostfollowed');
        $mftab->addDataset($mftabds);
        $menus["followers-mostfollowed"] =  $mftab;

        //Follower count history
        $follower_history_tpl = Utils::getPluginViewDirectory('statusnet').'statusnet.followercount.tpl';
        $trendtab = new MenuItem('Count history', 'Your follower count over time', $follower_history_tpl, 'followers');
        $trendtabds = new Dataset("follower_count_history_by_day", 'FollowerCountDAO', 'getHistory',
        array($instance->network_user_id, 'statusnet', 'DAY', 15));
        $trendtab->addDataset($trendtabds);
        $trendtabweekds = new Dataset("follower_count_history_by_week", 'FollowerCountDAO', 'getHistory',
        array($instance->network_user_id, 'statusnet', 'WEEK', 15));
        $trendtab->addDataset($trendtabweekds);
        $trendtabmonthds = new Dataset("follower_count_history_by_month", 'FollowerCountDAO', 'getHistory',
        array($instance->network_user_id, 'statusnet', 'MONTH', 11));
        $trendtabmonthds->addHelp('userguide/listings/statusnet/dashboard_followers-history');
        $trendtab->addDataset($trendtabmonthds);
        $menus['followers-history'] = $trendtab;

        //List membership count history
        $group_membership_history_tpl = Utils::getPluginViewDirectory('statusnet').'statusnet.listmembershipcount.tpl';
        $group_trend_tab = new MenuItem('List stats', 'Your list membership count over time',
        $group_membership_history_tpl, 'followers');
        $group_trend_tab_ds = new Dataset("list_membership_count_history_by_day", 'GroupMembershipCountDAO',
        'getHistory', array($instance->network_user_id, 'statusnet', 'DAY', 15));
        $group_trend_tab->addDataset($group_trend_tab_ds);
        $group_trend_tab_week_ds = new Dataset("list_membership_count_history_by_week", 'GroupMembershipCountDAO',
        'getHistory', array($instance->network_user_id, 'statusnet', 'WEEK', 15));
        $group_trend_tab->addDataset($group_trend_tab_week_ds);
        $group_trend_tab_month_ds = new Dataset("list_membership_count_history_by_month", 'GroupMembershipCountDAO',
        'getHistory', array($instance->network_user_id, 'statusnet', 'MONTH', 11));
        $group_trend_tab_month_ds->addHelp('userguide/listings/statusnet/dashboard_followers-liststats');
        $group_trend_tab->addDataset($group_trend_tab_month_ds);
        $menus['group-membership-history'] = $group_trend_tab;

        if ($rt_plugin_active) {
            $fvdtab = new MenuItem("Favorited by Others", "Favorited by Others", $statusnet_data_tpl, 'links');
            $ftab2 = new Dataset("all_favd", 'FavoritePostDAO', "getAllFavoritedPosts",
            array($instance->network_user_id, $instance->network, 20, '#page_number#'));
            $fvdtab->addDataset($ftab2);
            $menus["favd-all"] = $fvdtab;
        }
        //Links from favorites
        $lftab = new MenuItem('Links in favorites', 'Links in posts you favorited', $statusnet_data_tpl, 'links');
        $lftabds = new Dataset("links", 'LinkDAO', "getLinksByFavorites",
        array($instance->network_user_id, 'statusnet', 15, '#page_number#',!Session::isLoggedIn()));
        $lftabds->addHelp('userguide/listings/statusnet/dashboard_links-favorites');
        $lftab->addDataset($lftabds);
        $menus["links-favorites"] = $lftab;

        //Links from friends
        $fltab = new MenuItem('Links by who you follow', 'Links your friends posted', $statusnet_data_tpl, 'links');
        $fltabds = new Dataset("links", 'LinkDAO', "getLinksByFriends",
        array($instance->network_user_id, 'statusnet', 15, '#page_number#',!Session::isLoggedIn()));
        $fltabds->addHelp('userguide/listings/statusnet/dashboard_links-friends');
        $fltab->addDataset($fltabds);
        $menus["links-friends"] = $fltab;

        //Photos
        $ptab = new MenuItem("Photos by who you follow", 'Photos your friends have posted', $statusnet_data_tpl, 'links');
        $ptabds = new Dataset("links", 'LinkDAO', "getPhotosByFriends",
        array($instance->network_user_id, 'statusnet', 15, '#page_number#',!Session::isLoggedIn()));
        $ptabds->addHelp('userguide/listings/statusnet/dashboard_links-photos');
        $ptab->addDataset($ptabds);
        $menus["links-photos"] = $ptab;

        return $menus;
    }

    /**
     * Defines the ordering of replies in the post page (/post/?t=...)
     *
     * @param $order_by Order by distance ('location') or not ('default')
     * @return string Ordering, to be used in a SQL 'ORDER BY' statement
     */
    public static function repliesOrdering($order_by) {
        if ($order_by == 'location') {
            return "geo_status, reply_retweet_distance, is_reply_by_friend DESC, follower_count DESC";
        } else {
            return "is_reply_by_friend DESC, follower_count DESC";
        }
    }

    /**
     * @param Post $post
     * @return array MenuItems
     */
    public function getPostDetailMenuItems($post) {
        $statusnet_data_tpl = Utils::getPluginViewDirectory('statusnet').'statusnet.post.retweets.tpl';
        $menus = array();
        $rt_plugin_active = false;
        $plugin_dao = DAOFactory::getDAO('PluginDAO');
        $plugin_id = $plugin_dao->getPluginId('statusnetrealtime');
        if (isset($plugin_id)) {
            $rt_plugin_active = $plugin_dao->isPluginActive($plugin_id);
        }

        if ($post->network == 'statusnet') {
            $retweets_menu_item = new MenuItem("Retweets", "Retweets of this tweet", $statusnet_data_tpl);
            //if not logged in, show only public retweets
            $retweets_dataset = new Dataset("retweets", 'PostDAO', "getRetweetsOfPost", array($post->post_id,
            'statusnet', 'default', 'km', !Session::isLoggedIn()) );
            $retweets_menu_item->addDataset($retweets_dataset);
            $menus['fwds'] = $retweets_menu_item;
            if ($rt_plugin_active) {
                $favd_menu_item = new MenuItem("Favorited", "Those who favorited this tweet", $statusnet_data_tpl);
                //if not logged in, show only public fav'd info
                $favd_dataset = new Dataset("favds", 'FavoritePostDAO', "getUsersWhoFavedPost", array($post->post_id,
                'statusnet', !Session::isLoggedIn()) );
                $favd_menu_item->addDataset($favd_dataset);
                $menus['favs'] = $favd_menu_item;
            }
        }
        return $menus;
    }

    /**
     * Allocates api call counts to each crawler function
     * @param int $max_api_calls_per_crawl
     * @param bool $noauth
     * @return @array Budget array
     */
    public function budgetCrawlLimits($max_api_calls_per_crawl, $noauth) {
        $budget_array_config = $noauth ? $this->api_budget_allocation_noauth : $this->api_budget_allocation_auth;
        $budget_array = array();
        foreach($budget_array_config as $function_name => $value) {
            $count = intval( $max_api_calls_per_crawl * ($value['percent'] * .01) );
            $budget_array[$function_name] = array('count' => $count, 'remaining' => $count);
        }
        return $budget_array;
    }
}
