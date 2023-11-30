<?php
// This file is part of Moodle - http://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

use core\moodle_database_for_testing;
use EduSharingApiClient\CurlHandler as EdusharingCurlHandler;
use EduSharingApiClient\CurlResult;
use EduSharingApiClient\EduSharingAuthHelper;
use EduSharingApiClient\EduSharingHelperBase;
use EduSharingApiClient\EduSharingNodeHelper;
use EduSharingApiClient\EduSharingNodeHelperConfig;
use EduSharingApiClient\NodeDeletedException;
use EduSharingApiClient\UrlHandling;
use EduSharingApiClient\Usage;
use EduSharingApiClient\UsageDeletedException;
use mod_edusharing\EduSharingService;
use mod_edusharing\MoodleCurlHandler;
use mod_edusharing\UtilityFunctions;
use testUtils\FakeConfig;

/**
 * Class EdusharingServiceTest
 *
 * @author Marian Ziegler <ziegler@edu-sharing.net>
 */
class EdusharingServiceTest extends advanced_testcase {
    /**
     * Function test_if_get_ticket_returns_existing_ticket_if_cached_ticket_is_new
     *
     * @return void
     *
     * @backupGlobals enabled
     * @throws Exception
     */
    public function test_if_get_ticket_returns_existing_ticket_if_cached_ticket_is_new(): void {
        global $USER, $CFG;
        require_once($CFG->dirroot . '/mod/edusharing/tests/testUtils/FakeConfig.php');
        $fakeconfig = new FakeConfig();
        $fakeconfig->set_entries([
            'application_cc_gui_url'  => 'www.url.de',
            'application_private_key' => 'pkey123',
            'application_appid'       => 'appid123',
        ]);
        $utils                                   = new UtilityFunctions($fakeconfig);
        $service                                 = new EduSharingService(utils: $utils);
        $USER->edusharing_userticket             = 'testTicket';
        $USER->edusharing_userticketvalidationts = time();
        $this->assertEquals('testTicket', $service->getTicket());
    }

    /**
     * Function test_if_get_ticket_returns_existing_ticket_if_auth_info_is_ok
     *
     * @return void
     *
     * @backupGlobals enabled
     * @throws dml_exception
     * @throws Exception
     */
    public function test_if_get_ticket_returns_existing_ticket_if_auth_info_is_ok(): void {
        global $USER;
        unset($USER->edusharing_userticketvalidationts);
        $USER->edusharing_userticket = 'testTicket';
        $basehelper                  = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $authmock                    = $this->getMockBuilder(EduSharingAuthHelper::class)
            ->setConstructorArgs([$basehelper])
            ->onlyMethods(['getTicketAuthenticationInfo'])
            ->getMock();
        $authmock->expects($this->once())
            ->method('getTicketAuthenticationInfo')
            ->will($this->returnValue(['statusCode' => 'OK']));
        $nodeconfig  = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $nodehandler = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $service     = new EduSharingService($authmock, $nodehandler);
        $this->assertEquals('testTicket', $service->getTicket());
        $this->assertTrue(time() - $USER->edusharing_userticketvalidationts < 10);
    }

    /**
     * Function test_if_getT_ticket_returns_ticket_from_auth_helper_if_no_cached_ticket_exists
     *
     * @backupGlobals enabled
     * @return void
     * @throws dml_exception
     */
    public function test_if_get_ticket_returns_ticket_from_auth_helper_if_no_cached_ticket_exists(): void {
        global $USER;
        unset($USER->edusharing_userticket);
        $basehelper = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $authmock   = $this->getMockBuilder(EduSharingAuthHelper::class)
            ->setConstructorArgs([$basehelper])
            ->onlyMethods(['getTicketForUser', 'getTicketAuthenticationInfo'])
            ->getMock();
        $authmock->expects($this->once())
            ->method('getTicketForUser')
            ->will($this->returnValue('ticketForUser'));
        $utilsmock = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getAuthKey'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getAuthKey')
            ->will($this->returnValue('neverMind'));
        $nodeconfig  = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $nodehandler = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $service     = new EduSharingService($authmock, $nodehandler, $utilsmock);
        $this->assertEquals('ticketForUser', $service->getTicket());
        $USER->edusharing_userticket = 'testTicket';
    }

    /**
     * Function test_if_get_ticket_returns_ticket_from_auth_helper_if_ticket_is_too_old_and_auth_info_call_fails
     *
     * @backupGlobals enabled
     * @return void
     * @throws dml_exception
     */
    public function test_if_get_ticket_returns_ticket_from_auth_helper_if_ticket_is_too_old_and_auth_info_call_fails(): void {
        global $USER;
        $USER->edusharing_userticket             = 'testTicket';
        $USER->edusharing_userticketvalidationts = 1689769393;
        $basehelper                              = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $authmock                                = $this->getMockBuilder(EduSharingAuthHelper::class)
            ->setConstructorArgs([$basehelper])
            ->onlyMethods(['getTicketForUser', 'getTicketAuthenticationInfo'])
            ->getMock();
        $authmock->expects($this->once())
            ->method('getTicketForUser')
            ->will($this->returnValue('ticketForUser'));
        $authmock->expects($this->once())
            ->method('getTicketAuthenticationInfo')
            ->will($this->returnValue(['statusCode' => 'NOT_OK']));
        $utilsmock = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getAuthKey'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getAuthKey')
            ->will($this->returnValue('neverMind'));
        $nodeconfig  = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $nodehandler = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $service     = new EduSharingService($authmock, $nodehandler, $utilsmock);
        $this->assertEquals('ticketForUser', $service->getTicket());
        $USER->edusharing_userticket = 'testTicket';
    }

    /**
     * Function test_if_create_usage_calls_node_helper_method_with_correct_params
     */
    public function test_if_create_usage_calls_node_helper_method_with_correct_params(): void {
        $usageobject              = new stdClass();
        $usageobject->containerId = 'containerIdTest';
        $usageobject->resourceId  = 'resourceIdTest';
        $usageobject->nodeId      = 'nodeIdTest';
        $usageobject->nodeVersion = 'nodeVersion';
        $basehelper               = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig               = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper               = new EduSharingAuthHelper($basehelper);
        $nodehelpermock           = $this->getMockBuilder(EduSharingNodeHelper::class)
            ->onlyMethods(['createUsage'])
            ->setConstructorArgs([$basehelper, $nodeconfig])
            ->getMock();
        $nodehelpermock->expects($this->once())
            ->method('createUsage')
            ->with('ticketTest', 'containerIdTest', 'resourceIdTest', 'nodeIdTest', 'nodeVersion');
        $servicemock = $this->getMockBuilder(EduSharingService::class)
            ->onlyMethods(['getTicket'])
            ->setConstructorArgs([$authhelper, $nodehelpermock])
            ->getMock();
        $servicemock->expects($this->once())
            ->method('getTicket')
            ->will($this->returnValue('ticketTest'));
        $servicemock->createUsage($usageobject);
    }

    /**
     * Function test_if_get_usage_id_calls_node_helper_method_with_correct_params_and_returns_result
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_get_usage_id_calls_node_helper_method_with_correct_params_and_returns_result(): void {
        $usageobject              = new stdClass();
        $usageobject->containerId = 'containerIdTest';
        $usageobject->resourceId  = 'resourceIdTest';
        $usageobject->nodeId      = 'nodeIdTest';
        $usageobject->ticket      = 'ticketTest';
        $basehelper               = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig               = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper               = new EduSharingAuthHelper($basehelper);
        $nodehelpermock           = $this->getMockBuilder(EduSharingNodeHelper::class)
            ->onlyMethods(['getUsageIdByParameters'])
            ->setConstructorArgs([$basehelper, $nodeconfig])
            ->getMock();
        $nodehelpermock->expects($this->once())
            ->method('getUsageIdByParameters')
            ->with('ticketTest', 'nodeIdTest', 'containerIdTest', 'resourceIdTest')
            ->will($this->returnValue('expectedId'));
        $service = new EduSharingService($authhelper, $nodehelpermock);
        $id      = $service->getUsageId($usageobject);
        $this->assertEquals('expectedId', $id);
    }

    /**
     * Function test_if_get_usage_id_throws_exception_if_node_helper_method_returns_null
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_get_usage_id_throws_exception_if_node_helper_method_returns_null(): void {
        $usageobject              = new stdClass();
        $usageobject->containerId = 'containerIdTest';
        $usageobject->resourceId  = 'resourceIdTest';
        $usageobject->nodeId      = 'nodeIdTest';
        $usageobject->ticket      = 'ticketTest';
        $basehelper               = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig               = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper               = new EduSharingAuthHelper($basehelper);
        $nodehelpermock           = $this->getMockBuilder(EduSharingNodeHelper::class)
            ->onlyMethods(['getUsageIdByParameters'])
            ->setConstructorArgs([$basehelper, $nodeconfig])
            ->getMock();
        $nodehelpermock->expects($this->once())
            ->method('getUsageIdByParameters')
            ->with('ticketTest', 'nodeIdTest', 'containerIdTest', 'resourceIdTest')
            ->will($this->returnValue(null));
        $service = new EduSharingService($authhelper, $nodehelpermock);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No usage found');
        $service->getUsageId($usageobject);
    }

    /**
     * Function test_if_delete_usage_calls_node_helper_method_with_proper_params
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_delete_usage_calls_node_helper_method_with_proper_params(): void {
        $usageobject          = new stdClass();
        $usageobject->nodeId  = 'nodeIdTest';
        $usageobject->usageId = 'usageIdTest';
        $basehelper           = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig           = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper           = new EduSharingAuthHelper($basehelper);
        $nodehelpermock       = $this->getMockBuilder(EduSharingNodeHelper::class)
            ->onlyMethods(['deleteUsage'])
            ->setConstructorArgs([$basehelper, $nodeconfig])
            ->getMock();
        $nodehelpermock->expects($this->once())
            ->method('deleteUsage')
            ->with('nodeIdTest', 'usageIdTest');
        $service = new EduSharingService($authhelper, $nodehelpermock);
        $service->deleteUsage($usageobject);
    }

    /**
     * Function test_if_get_node_calls_node_helper_method_with_proper_params
     *
     * @return void
     * @throws JsonException
     * @throws NodeDeletedException
     * @throws UsageDeletedException
     * @throws dml_exception
     */
    public function test_if_get_node_calls_node_helper_method_with_proper_params(): void {
        $usageobject              = new stdClass();
        $usageobject->nodeId      = 'nodeIdTest';
        $usageobject->usageId     = 'usageIdTest';
        $usageobject->nodeVersion = 'nodeVersionTest';
        $usageobject->containerId = 'containerIdTest';
        $usageobject->resourceId  = 'resourceIdTest';
        $usage                    = new Usage(
            $usageobject->nodeId,
            $usageobject->nodeVersion,
            $usageobject->containerId,
            $usageobject->resourceId,
            $usageobject->usageId
        );
        $basehelper               = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig               = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper               = new EduSharingAuthHelper($basehelper);
        $nodehelpermock           = $this->getMockBuilder(EduSharingNodeHelper::class)
            ->onlyMethods(['getNodeByUsage'])
            ->setConstructorArgs([$basehelper, $nodeconfig])
            ->getMock();
        $nodehelpermock->expects($this->once())
            ->method('getNodeByUsage')
            ->with($usage);
        $service = new EduSharingService($authhelper, $nodehelpermock);
        $service->getNode($usageobject);
    }

    /**
     * test_if_update_instance_calls_db_methods_and_calls_creation_method_with_proper_params
     *
     * @return void
     *
     * @backupGlobals enabled
     */
    public function test_if_update_instance_calls_db_methods_and_calls_creation_method_with_proper_params(): void {
        require_once('lib/dml/tests/dml_test.php');
        $currenttime                   = time();
        $eduobject                     = new stdClass();
        $eduobject->object_url         = 'inputUrl';
        $eduobject->course             = 'containerIdTest';
        $eduobject->object_version     = 'nodeVersionTest';
        $eduobject->id                 = 'resourceIdTest';
        $eduobjectupdate               = clone($eduobject);
        $eduobjectupdate->usage_id     = '2';
        $eduobjectupdate->timecreated  = $currenttime;
        $eduobjectupdate->timeupdated  = $currenttime;
        $eduobjectupdate->options      = '';
        $eduobjectupdate->popup_window = '';
        $eduobjectupdate->tracking     = 0;
        $usagedata                     = new stdClass();
        $usagedata->containerId        = 'containerIdTest';
        $usagedata->resourceId         = 'resourceIdTest';
        $usagedata->nodeId             = 'outputUrl';
        $usagedata->nodeVersion        = 'nodeVersionTest';
        $usagedata->ticket             = 'ticketTest';
        $memento                       = new stdClass();
        $memento->id                   = 'someId';
        $basehelper                    = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig                    = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper                    = new EduSharingAuthHelper($basehelper);
        $nodehelper                    = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $utilsmock                     = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getObjectIdFromUrl'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getObjectIdFromUrl')
            ->with('inputUrl')
            ->will($this->returnValue('outputUrl'));
        $servicemock = $this->getMockBuilder(EduSharingService::class)
            ->onlyMethods(['createUsage', 'getTicket'])
            ->setConstructorArgs([$authhelper, $nodehelper, $utilsmock])
            ->getMock();
        $servicemock->expects($this->once())
            ->method('getTicket')
            ->will($this->returnValue('ticketTest'));
        $servicemock->expects($this->once())
            ->method('createUsage')
            ->with($usagedata)
            ->will($this->returnValue(new Usage('whatever', 'whatever', 'whatever', 'whatever', '2')));
        $dbmock = $this->getMockBuilder(moodle_database_for_testing::class)
            ->onlyMethods(['get_record', 'update_record'])
            ->getMock();
        $dbmock->expects($this->once())
            ->method('get_record')
            ->with('edusharing', ['id' => 'resourceIdTest'], '*', MUST_EXIST)
            ->will($this->returnValue($memento));
        $dbmock->expects($this->once())
            ->method('update_record')
            ->with('edusharing', $eduobjectupdate);
        $GLOBALS['DB'] = $dbmock;
        $this->assertEquals(true, $servicemock->updateInstance($eduobject, $currenttime));
    }

    /**
     * Function test_if_update_instance_resets_data_and_returns_false_on_update_error
     *
     * @return void
     *
     * @backupGlobals enabled
     */
    public function test_if_update_instance_resets_data_and_returns_false_on_update_error(): void {
        require_once('lib/dml/tests/dml_test.php');
        $currenttime                   = time();
        $eduobject                     = new stdClass();
        $eduobject->object_url         = 'inputUrl';
        $eduobject->course             = 'containerIdTest';
        $eduobject->object_version     = 'nodeVersionTest';
        $eduobject->id                 = 'resourceIdTest';
        $eduobjectupdate               = clone($eduobject);
        $eduobjectupdate->usage_id     = '2';
        $eduobjectupdate->timecreated  = $currenttime;
        $eduobjectupdate->timeupdated  = $currenttime;
        $eduobjectupdate->options      = '';
        $eduobjectupdate->popup_window = '';
        $eduobjectupdate->tracking     = 0;
        $usagedata                     = new stdClass();
        $usagedata->containerId        = 'containerIdTest';
        $usagedata->resourceId         = 'resourceIdTest';
        $usagedata->nodeId             = 'outputUrl';
        $usagedata->nodeVersion        = 'nodeVersionTest';
        $usagedata->ticket             = 'ticketTest';
        $memento                       = new stdClass();
        $memento->id                   = 'someId';
        $basehelper                    = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig                    = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper                    = new EduSharingAuthHelper($basehelper);
        $nodehelper                    = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $utilsmock                     = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getObjectIdFromUrl'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getObjectIdFromUrl')
            ->with('inputUrl')
            ->will($this->returnValue('outputUrl'));
        $servicemock = $this->getMockBuilder(EduSharingService::class)
            ->onlyMethods(['createUsage', 'getTicket'])
            ->setConstructorArgs([$authhelper, $nodehelper, $utilsmock])
            ->getMock();
        $servicemock->expects($this->once())
            ->method('getTicket')
            ->will($this->returnValue('ticketTest'));
        $servicemock->expects($this->once())
            ->method('createUsage')
            ->with($usagedata)
            ->willThrowException(new Exception(''));
        $dbmock = $this->getMockBuilder(moodle_database_for_testing::class)
            ->onlyMethods(['get_record', 'update_record'])
            ->getMock();
        $dbmock->expects($this->once())
            ->method('get_record')
            ->with('edusharing', ['id' => 'resourceIdTest'], '*', MUST_EXIST)
            ->will($this->returnValue($memento));
        $dbmock->expects($this->once())
            ->method('update_record')
            ->with('edusharing', $memento);
        $GLOBALS['DB'] = $dbmock;
        $this->assertEquals(false, $servicemock->updateInstance($eduobject, $currenttime));
    }

    /**
     * Function test_if_add_instance_calls_db_functions_and_service_method_with_correct_parameters
     *
     * @return void
     *
     * @backupGlobals enabled
     */
    public function test_if_add_instance_calls_db_functions_and_service_method_with_correct_parameters(): void {
        require_once('lib/dml/tests/dml_test.php');
        $currenttime                        = time();
        $eduobject                          = new stdClass();
        $eduobject->object_url              = 'inputUrl';
        $eduobject->course                  = 'containerIdTest';
        $eduobject->object_version          = '1.0';
        $eduobject->id                      = 'resourceIdTest';
        $processededuobject                 = clone($eduobject);
        $processededuobject->object_version = '1.0';
        $processededuobject->timecreated    = $currenttime;
        $processededuobject->timemodified   = $currenttime;
        $processededuobject->timeupdated    = $currenttime;
        $processededuobject->options        = '';
        $processededuobject->popup_window   = '';
        $processededuobject->tracking       = 0;
        $insertededuobject                  = clone($processededuobject);
        $insertededuobject->id              = 3;
        $insertededuobject->usage_id        = 4;
        $insertededuobject->object_version  = '1.0';
        $usagedata                          = new stdClass();
        $usagedata->containerId             = 'containerIdTest';
        $usagedata->resourceId              = 3;
        $usagedata->nodeId                  = 'outputUrl';
        $usagedata->nodeVersion             = '1.0';
        $dbmock                             = $this->getMockBuilder(moodle_database_for_testing::class)
            ->onlyMethods(['insert_record', 'update_record', 'delete_records'])
            ->getMock();
        $dbmock->expects($this->once())
            ->method('insert_record')
            ->with('edusharing', $processededuobject)
            ->will($this->returnValue(3));
        $dbmock->expects($this->once())
            ->method('update_record')
            ->with('edusharing', $insertededuobject);
        $GLOBALS['DB'] = $dbmock;
        $utilsmock     = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getObjectIdFromUrl'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getObjectIdFromUrl')
            ->with('inputUrl')
            ->will($this->returnValue('outputUrl'));
        $basehelper  = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig  = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper  = new EduSharingAuthHelper($basehelper);
        $nodehelper  = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $servicemock = $this->getMockBuilder(EduSharingService::class)
            ->onlyMethods(['createUsage', 'getTicket'])
            ->setConstructorArgs([$authhelper, $nodehelper, $utilsmock])
            ->getMock();
        $servicemock->expects($this->once())
            ->method('createUsage')
            ->with($usagedata)
            ->will($this->returnValue(new Usage('whatever', 'nodeVersionTest', 'whatever', 'whatever', '4')));
        $this->assertEquals(3, $servicemock->addInstance($eduobject));
    }

    /**
     * Function test_if_add_instance_returns_false_and_resets_data_on_creation_failure
     *
     * @return void
     *
     * @backupGlobals enabled
     */
    public function test_if_add_instance_returns_false_and_resets_data_on_creation_failure(): void {
        require_once('lib/dml/tests/dml_test.php');
        $currenttime                        = time();
        $eduobject                          = new stdClass();
        $eduobject->object_url              = 'inputUrl';
        $eduobject->course                  = 'containerIdTest';
        $eduobject->object_version          = '1';
        $eduobject->id                      = 'resourceIdTest';
        $processededuobject                 = clone($eduobject);
        $processededuobject->object_version = '1';
        $processededuobject->timecreated    = $currenttime;
        $processededuobject->timemodified   = $currenttime;
        $processededuobject->timeupdated    = $currenttime;
        $processededuobject->options        = '';
        $processededuobject->popup_window   = '';
        $processededuobject->tracking       = 0;
        $insertededuobject                  = clone($processededuobject);
        $insertededuobject->id              = 3;
        $insertededuobject->usage_id        = 4;
        $insertededuobject->object_version  = 'nodeVersionTest';
        $usagedata                          = new stdClass();
        $usagedata->containerId             = 'containerIdTest';
        $usagedata->resourceId              = 3;
        $usagedata->nodeId                  = 'outputUrl';
        $usagedata->nodeVersion             = '1';
        $dbmock                             = $this->getMockBuilder(moodle_database_for_testing::class)
            ->onlyMethods(['insert_record', 'update_record', 'delete_records'])
            ->getMock();
        $dbmock->expects($this->once())
            ->method('insert_record')
            ->with('edusharing', $processededuobject)
            ->will($this->returnValue(3));
        $dbmock->expects($this->never())
            ->method('update_record');
        $dbmock->expects($this->once())
            ->method('delete_records')
            ->with('edusharing', ['id' => 3]);
        $GLOBALS['DB'] = $dbmock;
        $utilsmock     = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getObjectIdFromUrl'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getObjectIdFromUrl')
            ->with('inputUrl')
            ->will($this->returnValue('outputUrl'));
        $basehelper  = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig  = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper  = new EduSharingAuthHelper($basehelper);
        $nodehelper  = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $servicemock = $this->getMockBuilder(EduSharingService::class)
            ->onlyMethods(['createUsage', 'getTicket'])
            ->setConstructorArgs([$authhelper, $nodehelper, $utilsmock])
            ->getMock();
        $servicemock->expects($this->once())
            ->method('createUsage')
            ->with($usagedata)
            ->willThrowException(new Exception(''));
        $this->assertEquals(false, $servicemock->addInstance($eduobject));
    }

    /**
     * Function test_if_delete_usage_throwsexception_if_provided_object_has_no_usage_id
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_delete_usage_throwsexception_if_provided_object_has_no_usage_id(): void {
        $usageobject         = new stdClass();
        $usageobject->nodeId = 'nodeIdTest';
        $basehelper          = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $nodeconfig          = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper          = new EduSharingAuthHelper($basehelper);
        $nodehelpermock      = $this->getMockBuilder(EduSharingNodeHelper::class)
            ->onlyMethods(['deleteUsage'])
            ->setConstructorArgs([$basehelper, $nodeconfig])
            ->getMock();
        $nodehelpermock->expects($this->never())
            ->method('deleteUsage');
        $service = new EduSharingService($authhelper, $nodehelpermock);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('No usage id provided, deletion cannot be performed');
        $service->deleteUsage($usageobject);
    }

    /**
     * Function test_if_delete_instance_calls_database_with_proper_params
     *
     * @backupGlobals enabled
     * @return void
     * @throws dml_exception
     */
    public function test_if_delete_instance_calls_database_with_proper_params(): void {
        require_once('lib/dml/tests/dml_test.php');
        $dbrecord             = new stdClass();
        $dbrecord->id         = 'edusharingId123';
        $dbrecord->object_url = 'test.de';
        $dbrecord->course     = 'container123';
        $dbrecord->resourceId = 'resource123';
        $id                   = 1;
        $dbmock               = $this->getMockBuilder(moodle_database_for_testing::class)
            ->onlyMethods(['get_record', 'delete_records'])
            ->getMock();
        $dbmock->expects($this->once())
            ->method('get_record')
            ->with('edusharing', ['id' => $id], '*', MUST_EXIST)
            ->will($this->returnValue($dbrecord));
        $dbmock->expects($this->once())
            ->method('delete_records')
            ->with('edusharing', ['id' => 'edusharingId123']);
        $GLOBALS['DB'] = $dbmock;
        $basehelper    = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $authhelper    = new EduSharingAuthHelper($basehelper);
        $nodeconfig    = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $nodehelper    = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $utilsmock     = $this->getMockBuilder(UtilityFunctions::class)
            ->onlyMethods(['getObjectIdFromUrl'])
            ->getMock();
        $utilsmock->expects($this->once())
            ->method('getObjectIdFromUrl')
            ->with('test.de')
            ->will($this->returnValue('myNodeId123'));
        $servicemock = $this->getMockBuilder(EduSharingService::class)
            ->setConstructorArgs([$authhelper, $nodehelper, $utilsmock])
            ->onlyMethods(['getTicket', 'getUsageId', 'deleteUsage'])
            ->getMock();
        $servicemock->expects($this->once())
            ->method('getTicket')
            ->will($this->returnValue('ticket123'));
        $servicemock->expects($this->once())
            ->method('getUsageId')
            ->will($this->returnValue('usage123'));
        $servicemock->deleteInstance((string)$id);
    }

    /**
     * Function test_if_import_metadata_calls_curl_with_the_correct_params
     *
     * @backupGlobals enabled
     * @return void
     * @throws dml_exception
     */
    public function test_if_import_metadata_calls_curl_with_the_correct_params(): void {
        global $_SERVER;
        $_SERVER['HTTP_USER_AGENT'] = 'testAgent';
        $url                        = 'http://test.de';
        $expectedoptions            = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_HEADER         => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => 'testAgent',
        ];
        $curl                       = new CurlResult('testContent', 0, []);
        $basemock                   = $this->getMockBuilder(EduSharingHelperBase::class)
            ->setConstructorArgs(['www.url.de', 'pkey123', 'appid123'])
            ->onlyMethods(['handleCurlRequest'])
            ->getMock();
        $basemock->expects($this->once())
            ->method('handleCurlRequest')
            ->with($url, $expectedoptions)
            ->will($this->returnValue($curl));
        $nodeconfig = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper = new EduSharingAuthHelper($basemock);
        $nodehelper = new EduSharingNodeHelper($basemock, $nodeconfig);
        $service    = new EduSharingService($authhelper, $nodehelper);
        $this->assertEquals($curl, $service->importMetadata($url));
    }

    /**
     * Function test_if_validate_session_calls_curl_with_the_correct_params
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_validate_session_calls_curl_with_the_correct_params(): void {
        $url             = 'http://test.de';
        $headers         = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode('testAuth'),
        ];
        $expectedoptions = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER     => $headers,
        ];
        $curl            = new CurlResult('testContent', 0, []);
        $basemock        = $this->getMockBuilder(EduSharingHelperBase::class)
            ->setConstructorArgs(['www.url.de', 'pkey123', 'appid123'])
            ->onlyMethods(['handleCurlRequest'])
            ->getMock();
        $basemock->expects($this->once())
            ->method('handleCurlRequest')
            ->with($url . '/rest/authentication/v1/validateSession', $expectedoptions)
            ->will($this->returnValue($curl));
        $nodeconfig = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper = new EduSharingAuthHelper($basemock);
        $nodehelper = new EduSharingNodeHelper($basemock, $nodeconfig);
        $service    = new EduSharingService($authhelper, $nodehelper);
        $this->assertEquals($curl, $service->validateSession($url, 'testAuth'));
    }

    /**
     * Function test_if_register_plugin_calls_curl_with_the_correct_options
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_register_plugin_calls_curl_with_the_correct_options(): void {
        $url         = 'http://test.de';
        $delimiter   = 'delimiterTest';
        $body        = 'bodyTest';
        $auth        = 'authTest';
        $headers     = [
            'Content-Type: multipart/form-data; boundary=' . $delimiter,
            'Content-Length: ' . strlen($body),
            'Accept: application/json',
            'Authorization: Basic ' . base64_encode($auth),
        ];
        $curloptions = [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POSTFIELDS     => $body,
        ];
        $curl        = new CurlResult('testContent', 0, []);
        $basehelper  = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $curlmock    = $this->getMockBuilder(MoodleCurlHandler::class)
            ->onlyMethods(['handleCurlRequest', 'setMethod'])
            ->getMock();
        $curlmock->expects($this->once())
            ->method('setMethod')
            ->with(EdusharingCurlHandler::METHOD_PUT);
        $curlmock->expects($this->once())
            ->method('handleCurlRequest')
            ->with($url . '/rest/admin/v1/applications/xml', $curloptions)
            ->will($this->returnValue($curl));
        $basehelper->registerCurlHandler($curlmock);
        $nodeconfig = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper = new EduSharingAuthHelper($basehelper);
        $nodehelper = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $service    = new EduSharingService($authhelper, $nodehelper);
        $this->assertEquals($curl, $service->registerPlugin($url, $delimiter, $body, $auth));
    }

    /**
     * Function test_if_sign_calls_base_helper_method_with_correct_params_and_returns_its_returned_value
     *
     * @return void
     * @throws dml_exception
     */
    public function test_if_sign_calls_base_helper_method_with_correct_params_and_returns_its_returned_value(): void {
        $basemock = $this->getMockBuilder(EduSharingHelperBase::class)
            ->setConstructorArgs(['www.url.de', 'pkey123', 'appid123'])
            ->onlyMethods(['sign'])
            ->getMock();
        $basemock->expects($this->once())
            ->method('sign')
            ->with('testInput')
            ->will($this->returnValue('testOutput'));
        $nodeconfig = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper = new EduSharingAuthHelper($basemock);
        $nodehelper = new EduSharingNodeHelper($basemock, $nodeconfig);
        $service    = new EduSharingService($authhelper, $nodehelper);
        $this->assertEquals('testOutput', $service->sign('testInput'));
    }

    /**
     * Function test_get_render_html_calls_curl_handler_with_correct_params_and_returns_content_on_success
     *
     * @return void
     * @throws dml_exception
     *
     * @backupGlobals enabled
     */
    public function test_get_render_html_calls_curl_handler_with_correct_params_and_returns_content_on_success(): void {
        global $_SERVER;
        $_SERVER['HTTP_USER_AGENT'] = 'testAgent';
        $basehelper                 = new EduSharingHelperBase(
            'www.url.de',
            'pkey123',
            'appid123');
        $curloptions                = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_HEADER         => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'],
        ];
        $curlmock                   = $this->getMockBuilder(MoodleCurlHandler::class)
            ->onlyMethods(['handleCurlRequest'])
            ->getMock();
        $curlmock->expects($this->once())
            ->method('handleCurlRequest')
            ->with('www.testUrl.de', $curloptions)
            ->will($this->returnValue(new CurlResult('expectedContent', 0, [])));
        $basehelper->registerCurlHandler($curlmock);
        $nodeconfig = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper = new EduSharingAuthHelper($basehelper);
        $nodehelper = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $service    = new EduSharingService($authhelper, $nodehelper);
        $this->assertEquals('expectedContent', $service->getRenderHtml('www.testUrl.de'));
    }

    /**
     * Function test_get_render_html_returns_error_message_if_curl_result_has_error
     *
     * @return void
     * @throws dml_exception
     *
     * @backupGlobals enabled
     */
    public function test_get_render_html_returns_error_message_if_curl_result_has_error(): void {
        global $_SERVER;
        $_SERVER['HTTP_USER_AGENT'] = 'testAgent';
        $basehelper                 = new EduSharingHelperBase('www.url.de', 'pkey123', 'appid123');
        $curloptions                = [
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => 1,
            CURLOPT_HEADER         => 0,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'],
        ];
        $curlmock                   = $this->getMockBuilder(MoodleCurlHandler::class)
            ->onlyMethods(['handleCurlRequest'])
            ->getMock();
        $curlmock->expects($this->once())
            ->method('handleCurlRequest')
            ->with('www.testUrl.de', $curloptions)
            ->will($this->returnValue(new CurlResult('expectedContent', 1, ['message' => 'error'])));
        $basehelper->registerCurlHandler($curlmock);
        $nodeconfig = new EduSharingNodeHelperConfig(new UrlHandling(true));
        $authhelper = new EduSharingAuthHelper($basehelper);
        $nodehelper = new EduSharingNodeHelper($basehelper, $nodeconfig);
        $service    = new EduSharingService($authhelper, $nodehelper);
        $this->assertEquals('Unexpected Error', $service->getRenderHtml('www.testUrl.de'));
    }
}
