<?php
/**
 * @defgroup controllers_api_file File API controller
 */

/**
 * @file controllers/api/file/FileApiHandler.inc.php
 *
 * Copyright (c) 2014 Simon Fraser University Library
 * Copyright (c) 2000-2014 John Willinsky
 * Distributed under the GNU GPL v2. For full terms see the file docs/COPYING.
 *
 * @class FileApiHandler
 * @ingroup controllers_api_file
 *
 * @brief Class defining an AJAX API for supplying file information.
 */

// Import the base handler.
import('classes.handler.Handler');
import('lib.pkp.classes.core.JSONMessage');
import('lib.pkp.classes.file.SubmissionFileManager');
import('classes.security.authorization.SubmissionFileAccessPolicy');

class FileApiHandler extends Handler {

	/**
	 * Constructor.
	 */
	function FileApiHandler() {
		parent::Handler();
		$this->addRoleAssignment(
			array(ROLE_ID_MANAGER, ROLE_ID_SUB_EDITOR, ROLE_ID_ASSISTANT, ROLE_ID_REVIEWER, ROLE_ID_AUTHOR),
			array('downloadFile', 'downloadLibraryFile', 'viewFile', 'downloadAllFiles', 'recordDownload', 'enableLinkAction')
		);
	}

	/**
	 * record a file view.
	 * Must be overridden in subclases.
	 * @param $submissionFile SubmissionFile the file to record.
	 */
	function recordView($submissionFile) {
		SubmissionFileManager::recordView($submissionFile);
	}

	//
	// Implement methods from PKPHandler
	//
	function authorize($request, &$args, $roleAssignments) {
		$fileIds = $request->getUserVar('filesIdsAndRevisions');
		$libraryFileId = $request->getUserVar('libraryFileId');

		if (is_string($fileIds)) {
			$fileIdsArray = explode(';', $fileIds);
			// Remove empty entries (a trailing ";" will cause these)
			$fileIdsArray = array_filter($fileIdsArray, create_function('$a', 'return !empty($a);'));
		}
		if (!empty($fileIdsArray)) {
			$multipleSubmissionFileAccessPolicy = new PolicySet(COMBINING_DENY_OVERRIDES);
			foreach ($fileIdsArray as $fileIdAndRevision) {
				$multipleSubmissionFileAccessPolicy->addPolicy($this->_getAccessPolicy($request, $args, $roleAssignments, $fileIdAndRevision));
			}
			$this->addPolicy($multipleSubmissionFileAccessPolicy);
		} else if (is_numeric($libraryFileId)) {
			import('lib.pkp.classes.security.authorization.PkpContextAccessPolicy');
			$this->addPolicy(new PkpContextAccessPolicy($request, $roleAssignments));
		} else {
			// IDs will be specified using the default parameters.
			$this->addPolicy($this->_getAccessPolicy($request, $args, $roleAssignments));
		}

		return parent::authorize($request, $args, $roleAssignments);
	}

	//
	// Public handler methods
	//
	/**
	 * Download a file.
	 * @param $args array
	 * @param $request Request
	 */
	function downloadFile($args, $request) {
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		assert($submissionFile); // Should have been validated already
		$context = $request->getContext();
		$fileManager = $this->_getFileManager($context->getId(), $submissionFile->getSubmissionId());
		$fileManager->downloadFile($submissionFile->getFileId(), $submissionFile->getRevision(), false, $submissionFile->getClientFileName());
	}

	/**
	 * Download a library file.
	 * @param $args array
	 * @param $request Request
	 */
	function downloadLibraryFile($args, $request) {
		import('classes.file.LibraryFileManager');
		$context = $request->getContext();
		$libraryFileManager = new LibraryFileManager($context->getId());
		$libraryFileDao = DAORegistry::getDAO('LibraryFileDAO');
		$libraryFile = $libraryFileDao->getById($request->getUserVar('libraryFileId'));
		if ($libraryFile) {

			// If this file has a submission ID, ensure that the current
			// user is assigned to that submission.
			if ($libraryFile->getSubmissionId()) {
				$user = $request->getUser();
				$allowedAccess = false;
				$userStageAssignmentDao = DAORegistry::getDAO('UserStageAssignmentDAO');
				$assignedUsers = $userStageAssignmentDao->getUsersBySubmissionAndStageId($libraryFile->getSubmissionId(), WORKFLOW_STAGE_ID_SUBMISSION);
				if (!$assignedUsers->wasEmpty()) {
					while ($assignedUser = $assignedUsers->next()) {
						if ($assignedUser->getId()  == $user->getId()) {
							$allowedAccess = true;
							break;
						}
					}
				}
			} else {
				$allowedAccess = true; // this is a Context submission document, default to access policy.
			}

			if ($allowedAccess) {
				$filePath = $libraryFileManager->getBasePath() .  $libraryFile->getOriginalFileName();
				$libraryFileManager->downloadFile($filePath);
			} else {
				fatalError('Unauthorized access to library file.');
			}
		}
	}

	/**
	 * View a file.
	 * @param $args array
	 * @param $request Request
	 */
	function viewFile($args, $request) {
		$submissionFile = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILE);
		assert($submissionFile); // Should have been validated already
		$context = $request->getContext();
		$fileManager = $this->_getFileManager($context->getId(), $submissionFile->getSubmissionId());
		$fileManager->downloadFile($submissionFile->getFileId(), $submissionFile->getRevision(), true);
	}

	/**
	 * Download all passed files.
	 * @param $args array
	 * @param $request Request
	 */
	function downloadAllFiles($args, $request) {
		// Retrieve the authorized objects.
		$submissionFiles = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILES);
		$submission = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION);

		// Find out the paths of all files in this grid.
		$context = $request->getContext();
		$filePaths = array();
		$fileManager = $this->_getFileManager($context->getId(), $submission->getId());
		$filesDir = $fileManager->getBasePath();
		foreach ($submissionFiles as $submissionFile) {
			// Remove absolute path so the archive doesn't include it (otherwise all files are organized by absolute path)
			$filePaths[str_replace($filesDir, '', $submissionFile->getFilePath())] = $submissionFile->getClientFileName();

		}

		import('lib.pkp.classes.file.FileArchive');
		$fileArchive = new FileArchive();
		$archivePath = $fileArchive->create($filePaths, $filesDir);
		if (file_exists($archivePath)) {
			$fileManager = new FileManager();
			if ($fileArchive->zipFunctional()) {
				$fileManager->downloadFile($archivePath, 'application/x-zip', false, 'files.zip');
			} else {
				$fileManager->downloadFile($archivePath, 'application/x-gtar', false, 'files.tar.gz');
			}
			$fileManager->deleteFile($archivePath);
		} else {
			fatalError('Creating archive with submission files failed!');
		}
	}

	/**
	 * Record file download and return js event to update grid rows.
	 * @param $args array
	 * @param $request Request
	 * @return string
	 */
	function recordDownload($args, $request) {
		$submissionFiles = $this->getAuthorizedContextObject(ASSOC_TYPE_SUBMISSION_FILES);
		$fileId = null;

		foreach ($submissionFiles as $submissionFile) {
			$this->recordView($submissionFile);
			$fileId = $submissionFile->getFileId();
			unset($submissionFile);
		}

		if (count($submissionFiles) > 1) {
			$fileId = null;
		}

		return $this->enableLinkAction($args, $request);
	}

	/**
	 * Returns a data changd event to re-enable the link action.  Refactored out of
	 *  recordDownload since library files do not have downloads recorded and are in a
	 *  different context.
	 * @param $args aray
	 * @param $request Request
	 * @return string
	 */
	function enableLinkAction($args, $request) {
		return DAO::getDataChangedEvent();
	}

	/**
	 * return the application specific file manager.
	 * @param $contextId int the context for this manager.
	 * @param $submissionId int the submission id.
	 * @return SubmissionFileManager
	 */
	function _getFileManager($contextId, $submissionId) {
		return new SubmissionFileManager($contextId, $submissionId);
	}

	/**
	 * return the application specific file access policy.
	 * @param $request PKPRequest
	 * @param $args
	 * @param $roleAssignments array
	 * @param $fileIdAndRevision array optional
	 * @return SubmissionAccessPolicy
	 */
	function _getAccessPolicy($request, $args, $roleAssignments, $fileIdAndRevision = null) {
		return new SubmissionFileAccessPolicy($request, $args, $roleAssignments, SUBMISSION_FILE_ACCESS_READ, $fileIdAndRevision);
	}
}

?>
