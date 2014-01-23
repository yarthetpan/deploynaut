<?php

class DNRoot extends Controller implements PermissionProvider, TemplateGlobalProvider {

	/**
	 *
	 * @var array
	 */
	public static $allowed_actions = array(
		'projects',
		'update',
		'project',
		'environment',
		'metrics',
		'getDeployForm',
		'deploy',
		'deploylog',
		'getDataTransferForm',
		'transfer',
		'transferlog',
	);

	/**
	 * URL handlers pretending that we have a deep URL structure.
	 */
	public static $url_handlers = array(
		'project/$Project/environment/$Environment/DeployForm' => 'getDeployForm',
		'project/$Project/DataTransferForm' => 'getDataTransferForm',
		'project/$Project/environment/$Environment/metrics' => 'metrics',
		'project/$Project/environment/$Environment/deploy/$Identifier/log' => 'deploylog',
		'project/$Project/environment/$Environment/deploy/$Identifier' => 'deploy',
		'project/$Project/transfer/$Identifier/log' => 'transferlog',
		'project/$Project/transfer/$Identifier' => 'transfer',
		'project/$Project/environment/$Environment' => 'environment',
		'project/$Project/build/$Build' => 'build',
		'project/$Project/update' => 'update',
		'project/$Project' => 'project',
		'projects' => 'projects',
	);

	/**
	 *
	 * @var DNData
	 */
	protected $data;

	/**
	 *
	 */
	public function init() {
		parent::init();

		if(!Permission::check('DEPLOYNAUT_ACCESS')) {
			return Security::permissionFailure();
		}

		parent::init();
		Requirements::combine_files(
			'deploynaut.js',
			array(
				THIRDPARTY_DIR . '/jquery/jquery.js',
				'deploynaut/javascript/bootstrap.js',
				'deploynaut/javascript/deploynaut.js',

			)
		);

		Requirements::css(FRAMEWORK_ADMIN_DIR .'/thirdparty/chosen/chosen/chosen.css');
	}

	/**
	 *
	 * @return string
	 */
	public function Link() {
		return "naut/";
	}

	/**
	 * Actions
	 * 
	 * @return \SS_HTTPResponse
	 */
	public function index(SS_HTTPRequest $request) {
		return $this->redirect($this->Link() . 'projects/');
	}

	/**
	 * Action
	 * 
	 * @return string - HTML
	 */
	public function projects(SS_HTTPRequest $request) {
		return $this->customise(array('Title' => 'Projects'))->renderWith(array('DNRoot_projects', 'DNRoot'));
	}

	/**
	 * 
	 * @param SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function project(SS_HTTPRequest $request) {
		$project = $this->getCurrentProject();
		if(!$project) {
			return new SS_HTTPResponse("Project '" . $request->latestParam('Project') . "' not found.", 404);
		}
		return $project->customise(array(
			'DataTransferForm' => $this->getDataTransferForm($request)
		))->renderWith(array('DNRoot_project', 'DNRoot'));
	}

	/**
	 * 
	 * @param SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function environment(SS_HTTPRequest $request) {
		$project = $this->getCurrentProject();
		if(!$project) {
			return new SS_HTTPResponse("Project '" . $request->latestParam('Project') . "' not found.", 404);
		}

		$env = $this->getCurrentEnvironment($project);
		if(!$env) {
			return new SS_HTTPResponse("Environment '" . $request->latestParam('Environment') . "' not found.", 404);
		}

		return $env->customise(array(
			'DeployForm' => $this->getDeployForm($request),
		))->renderWith(array('DNRoot_environment', 'DNRoot'));
	}

	/**
	 * 
	 * @param SS_HTTPRequest $request
	 * @return \SS_HTTPResponse
	 */
	public function metrics($request) {
		$project = $this->getCurrentProject();
		if(!$project) {
			return new SS_HTTPResponse("Project '" . $request->latestParam('Project') . "' not found.", 404);
		}

		$env = $this->getCurrentEnvironment($project);
		if(!$env) {
			return new SS_HTTPResponse("Environment '" . $request->latestParam('Environment') . "' not found.", 404);
		}

		return $env->renderWith(array('DNRoot_metrics', 'DNRoot'));
	}

	/**
	 * Get the DNData object.
	 *
	 * @return DNData
	 */
	public function DNData() {
		return Injector::inst()->get('DNData');
	}

	/**
	 * Provide DNProjectList (with all projects enumerated within).
	 * 
	 * @return DataList
	 */
	public function DNProjectList() {
		return DataObject::get('DNProject')->filterByCallback(function($record) {
			return $record->canView();
		});
	}

	/**
	 * Construct the deployment form
	 * 
	 * @param SS_HTTPRequest $request
	 * @return Form
	 */
	public function getDeployForm(SS_HTTPRequest $request) {
		$project = $this->getCurrentProject();
		$environment = $this->getCurrentEnvironment($project);

		if(!$environment->canDeploy()) {
			return null;
		}
		
		if(!$project->repoExists()) {
			$literalField = new LiteralField('noRepoWarning', '<strong>The GIT repository is for the time being not available.</strong>');
			return Form::create($this, 'DeployForm', new FieldList($literalField), new FieldList());
		}

		$branches = array();
		foreach($project->DNBranchList() as $branch) {
			$sha = $branch->SHA();
			$name = $branch->Name();
			$branches[$sha] = $name . ' (' . substr($sha,0,8) . ', ' . $branch->LastUpdated()->TimeDiff() . ' old)';
		}

		$tags = array();
		foreach($project->DNTagList()->setLimit(null) as $tag) {
			$sha = $tag->SHA();
			$name = $tag->Name();

			$tags[$sha] = $name . ' (' . substr($sha,0,8) . ', ' . $tag->Created()->TimeDiff() . ' old)';
		}
		$tags = array_reverse($tags);

		$redeploy = array();
		foreach($project->DNEnvironmentList() as $dnEnvironment) {
			$envName = $dnEnvironment->Name;
			foreach($dnEnvironment->DeployHistory() as $deploy) {
				$sha = $deploy->SHA;
				if(!isset($redeploy[$envName])) {
					$redeploy[$envName] = array();
				}
				if(!isset($redeploy[$envName][$sha])) {
					$redeploy[$envName][$sha] = substr($sha,0,8) . ' (deployed ' . $deploy->obj('LastEdited')->Ago() . ')';
				}
			}
		}

		$releaseMethods = array();
		if($tags) {
			$releaseMethods[] = new SelectionGroup_Item(
				'Tag',
				new DropdownField('Tag', '', $tags),
				'Deploy a tagged release'
			);
		}
		if($branches) {
			$releaseMethods[] = new SelectionGroup_Item(
				'Branch',
				new DropdownField('Branch', '', $branches),
				'Deploy the latest version of a branch'
			);
		}
		if($redeploy) {
			$releaseMethods[] = new SelectionGroup_Item(
				'Redeploy',
				new GroupedDropdownField('Redeploy', '', $redeploy),
				'Redeploy a release that was previously deployed (to any environment)'
			);
		}
		
		$releaseMethods[] = new SelectionGroup_Item(
			'SHA',
			new Textfield('SHA', 'Please specify the full SHA'),
			'Deploy a specific SHA'
		);

		$field = new SelectionGroup('SelectRelease', $releaseMethods);
		$field->setValue('Tag');

		$form = new Form($this, 'DeployForm', new FieldList(
			$field
		), new FieldList(
			$deployAction = new FormAction('doDeploy', "Deploy to " . $environment->Name)
		));
		$deployAction->addExtraClass('btn');
		$form->disableSecurityToken();
		// Tweak the action so it plays well with our fake URL structure.
		$form->setFormAction($request->getURL().'/DeployForm');
		return $form;
	}

	/**
	 * Deployment form submission handler.
	 *
	 * Initiate a DNDeployment record and redirect to it for status polling
	 * 
	 * @return \SS_HTTPResponse
	 */
	public function doDeploy($data, $form) {
		if(in_array($data['SelectRelease'], array('Tag','Branch','Redeploy','SHA'))) {
			$buildName = $data[$data['SelectRelease']];
		} else {
			throw new LogicException("Bad release selection method '{$data['SelectRelease']}'");
		}
		$project = $this->getCurrentProject();
		$environment = $this->getCurrentEnvironment($project);
		$sha = $project->DNBuildList()->byName($buildName);

		$deployment = new DNDeployment;
		$deployment->EnvironmentID = $environment->ID;
		$deployment->SHA = $sha->FullName();
		$deployment->write();
		$deployment->start();
		
		$this->redirect($deployment->Link());
	}

	/**
	 * Action - Do the actual deploy
	 *
	 * @param SS_HTTPRequest $request
	 */
	public function deploy(SS_HTTPRequest $request) {
		$params = $request->params();
		$deployment = DNDeployment::get()->byId($params['Identifier']);

		if(!$deployment || !$deployment->ID) throw new SS_HTTPResponse_Exception('Deployment not found', 404);
		if(!$deployment->canView()) return Security::permissionFailure();

		$environment = $deployment->Environment();
		$project = $environment->Project();

		if($environment->Name != $params['Environment']) throw new LogicException("Environment in URL doesn't match this deploy");
		if($project->Name != $params['Project']) throw new LogicException("Project in URL doesn't match this deploy");

		return $this->customise(new ArrayData(array(
			'Deployment' => $deployment,
		)))->renderWith('DNRoot_deploy');
	}

	/**
	 * Action - Get the latest deploy log
	 *
	 * @return string
	 */
	public function deploylog(SS_HTTPRequest $request) {
		$params = $request->params();
		$deployment = DNDeployment::get()->byId($params['Identifier']);

		if(!$deployment || !$deployment->ID) throw new SS_HTTPResponse_Exception('Deployment not found', 404);
		if(!$deployment->canView()) return Security::permissionFailure();

		$environment = $deployment->Environment();
		$project = $environment->Project();

		if($environment->Name != $params['Environment']) throw new LogicException("Environment in URL doesn't match this deploy");
		if($project->Name != $params['Project']) throw new LogicException("Project in URL doesn't match this deploy");

		$log = $deployment->log();
		if($log->exists()) {
			$content = $log->content();
		} else {
			$content = 'Waiting for action to start';
		}

		$sendJSON = (strpos($request->getHeader('Accept'), 'application/json') !== false)
			|| $request->getExtension() == 'json';

		
		$content = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\n", $content);
		if($sendJSON) {
			$this->response->addHeader("Content-type", "application/json");
			return json_encode(array(
				'status' => $deployment->ResqueStatus(),
				'content' => $content,
			));

		} else {
			$this->response->addHeader("Content-type", "text/plain");
			return $content;
		}

	}

	/**
	 * @return Form
	 */
	public function getDataTransferForm($request) {
		$envs = $this->getCurrentProject()->DNEnvironmentList()
			->filterByCallback(function($item) {return $item->canDeploy();});

		$modesMap = array(
			'all' => 'Database and Assets',
			'db' => 'Database only',
			'assets' => 'Assets only',
		);

		$form = new Form(
			$this,
			'DataTransferForm',
			new FieldList(
				new DropdownField('EnvironmentID', 'Environment', $envs->map()),
				new DropdownField('Mode', 'Transfer', $modesMap)
			),
			new FieldList(
				FormAction::create('doDataTransfer', 'Transfer')->addExtraClass('btn')
			)
		);
		$form->setFormAction($request->getURL().'/DataTransferForm');

		return $form;
	}

	public function doDataTransfer($data, $form) {
		$project = $this->getCurrentProject();
		$member = Member::currentUser();
		
		$validEnvs = $this->getCurrentProject()->DNEnvironmentList()
			->filterByCallback(function($item) {return $item->canDeploy();});

		$environment = $validEnvs->find('ID', $data['EnvironmentID']);
		if(!$environment) {
			throw new LogicException('Invalid environment');
		}

		$job = new DNDataTransfer;
		$job->EnvironmentID = $environment->ID;
		$job->Direction = 'get';
		$job->Mode = $data['Mode'];
		$job->write();
		$job->start();
		
		$this->redirect($job->Link());
	}

	/**
	 * View into the log for a {@link DNDataTransfer}.
	 */
	public function transfer($request) {
		$params = $request->params();
		$transfer = DNDataTransfer::get()->byId($params['Identifier']);

		if(!$transfer || !$transfer->ID) throw new SS_HTTPResponse_Exception('Transfer not found', 404);
		if(!$transfer->canView()) return Security::permissionFailure();

		$environment = $transfer->Environment();
		$project = $environment->Project();

		if($project->Name != $params['Project']) throw new LogicException("Project in URL doesn't match this deploy");

		return $this->customise(new ArrayData(array(
			'Transfer' => $transfer,
		)))->renderWith('DNRoot_transfer');
	}

	/**
	 * Action - Get the latest deploy log
	 *
	 * @return string
	 */
	public function transferlog(SS_HTTPRequest $request) {
		$params = $request->params();
		$transfer = DNDataTransfer::get()->byId($params['Identifier']);

		if(!$transfer || !$transfer->ID) throw new SS_HTTPResponse_Exception('Transfer not found', 404);
		if(!$transfer->canView()) return Security::permissionFailure();

		$environment = $transfer->Environment();
		$project = $environment->Project();

		if($project->Name != $params['Project']) throw new LogicException("Project in URL doesn't match this deploy");

		$log = $transfer->log();
		if($log->exists()) {
			$content = $log->content();
		} else {
			$content = 'Waiting for action to start';
		}

		$sendJSON = (strpos($request->getHeader('Accept'), 'application/json') !== false)
			|| $request->getExtension() == 'json';

		
		$content = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\n", $content);
		if($sendJSON) {
			$this->response->addHeader("Content-type", "application/json");
			return json_encode(array(
				'status' => $transfer->ResqueStatus(),
				'content' => $content,
			));

		} else {
			$this->response->addHeader("Content-type", "text/plain");
			return $content;
		}

	}

	/**
	 * 
	 * @return array
	 */
	public static function get_template_global_variables() {
		return array(
			'RedisUnavailable' => 'RedisUnavailable',
			'RedisWorkersCount' => 'RedisWorkersCount'
		);
	}

	/**
	 * Returns an error message if redis is unavailable
	 *
	 * @return string
	 */
	public static function RedisUnavailable() {
		try {
			Resque::queues();
		} catch(Exception $e) {
			return $e->getMessage();
		}
		return '';
	}

	/**
	 * Returns the number of connected Redis workers
	 *
	 * @return int
	 */
	public static function RedisWorkersCount() {
		return count(Resque_Worker::all());
	}

	/**
	 *
	 * @return array
	 */
	public function providePermissions() {
		return array(
			"DEPLOYNAUT_ACCESS" => array(
				'name' => "Access to Deploynaut",
				'category' => "Deploynaut",
			),
		);
	}
	
	/**
	 * 
	 * @return DNProject
	 */
	protected function getCurrentProject() {
		return $this->DNProjectList()->filter('Name', $this->getRequest()->latestParam('Project'))->First();
	}
	
	/**
	 * 
	 * @param DNProject $project
	 * @return DNEnvironment
	 */
	protected function getCurrentEnvironment($project = null) {
		if(!$project) $project = $this->getCurrentProject();
		return $project->DNEnvironmentList()->filter('Name', $this->getRequest()->latestParam('Environment'))->First();
	}

	/**
	 * Helper method to allow templates to know whether they should show the 'Archive List' include or not.
	 * The actual permissions are set on a per-environment level, so we need to find out if this $member can upload to
	 * or download from *any* {@link DNEnvironment} that (s)he has access to.
	 *
	 * TODO To be replaced with a method that just returns the list of archives this {@link Member} has access to.
	 *
	 * @param Member $member The {@link Member} to check (or null to check the currently logged in Member)
	 * @return boolean true if $member has access to upload or download to at least one {@link DNEnvironment}.
	 */
	public function CanUploadOrDownloadArchives(Member $member = null) {
		if(!$member) $member = Member::currentUser();

		$allProjects = $this->DNProjectList();
		if(!$allProjects) return false;

		foreach($allProjects as $project) {
			if($project->Environments()) {
				foreach($project->Environments() as $environment) {
					if($environment->canUploadArchive($member) || $environment->canDownloadArchive($member)) {
						return true; // We can return early as we only need to know that we can access one environment
					}
				}
			}
		}
	}

	/**
	 * @return ArrayList The list of all archive files that can be accessed by the currently logged-in {@link Member}
	 */
	public function ArchiveList() {
		$projects = $this->DNProjectList();
		if(!$projects) return null;

		foreach($projects as $project) {
			$environments = $project->DNEnvironmentList()->filterByCallback(function($record) {
				return $record->canDownloadArchive() || $record->canUploadArchive();
			});

			if(!$environments) return null;

			$archives = new ArrayList();

			foreach($environments as $env) {
				if($env->DataArchives()->count() > 0) {
					foreach($env->DataArchives() as $pak) {
						$archives->push($pak);
					}
				}
			}

			return $archives;
		}

		return null;
	}
}