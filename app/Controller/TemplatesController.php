<?php

App::uses('AppController', 'Controller');
App::uses('Folder', 'Utility');
App::uses('File', 'Utility');

/**
 * Templates Controller
 *
 * @property Template $Templates
 */

class TemplatesController extends AppController {
	public $components = array('Security' ,'RequestHandler');

	public $paginate = array(
			'limit' => 50,
			'order' => array(
					'Template.id' => 'desc'
			)
	);

	public function beforeFilter() { // TODO REMOVE
		parent::beforeFilter();
		$this->Security->unlockedActions = array('saveElementSorting', 'populateEventFromTemplate', 'uploadFile', 'deleteTemporaryFile');
	}
	
	public function fetchFormFromTemplate($id) {
		
	}
	
	public function index() {
		$conditions = array();
		if (!$this->_isSiteAdmin()) {
			$conditions['OR'] = array('org' => $this->Auth->user('org'), 'share' => true);
		}
		if (!$this->_isSiteAdmin()) {
			$this->paginate = Set::merge($this->paginate,array(
					'conditions' =>
					array("OR" => array(
							array('org' => $this->Auth->user('org')),
							array('share' => true),
			))));
		}
		$this->set('list', $this->paginate());
	}
	
	public function edit($id) {
		$template = $this->Template->checkAuthorisation($id, $this->Auth->user(), true);
		if (!$this->_isSiteAdmin() && !$template) throw new MethodNotAllowedException('No template with the provided ID exists, or you are not authorised to edit it.');
		$this->set('mayModify', true);
		
		if ($this->request->is('post') || $this->request->is('put')) {
			$this->request->data['Template']['id'] = $id;
			
			unset($this->request->data['Template']['tagsPusher']);
			$tags = $this->request->data['Template']['tags'];
			unset($this->request->data['Template']['tags']);
			$this->request->data['Template']['org'] = $this->Auth->user('org');
			$this->Template->create();
			if ($this->Template->save($this->request->data)) {
				$id = $this->Template->id;
				$tagArray = json_decode($tags);
				$this->loadModel('TemplateTag');
				$oldTags = $this->TemplateTag->find('all', array(
					'conditions' => array('template_id' => $id),
					'recursive' => -1,
					'contain' => 'Tag'
				));

				$newTags = $this->TemplateTag->Tag->find('all', array(
					'recursive' => -1,
					'conditions' => array('name' => $tagArray)
				));
				
				foreach($oldTags as $k => $oT) {
					if (!in_array($oT['Tag'], $newTags)) $this->TemplateTag->delete($oT['TemplateTag']['id']); 
				}
				
				foreach($newTags as $k => $nT) {
					if (!in_array($nT['Tag'], $oldTags)) {
						$this->TemplateTag->create();
						$this->TemplateTag->save(array('TemplateTag' => array('template_id' => $id, 'tag_id' => $nT['Tag']['id'])));
					}
				}
				$this->redirect(array('action' => 'view', $this->Template->id));
			} else {
				throw new Exception('The template could not be edited.');
			}
		}
		$this->request->data = $template;

		// get all existing tags for the tag add dropdown menu
		$this->loadModel('Tags');
		$tags = $this->Tags->find('all');
		$tagArray = array();
		foreach ($tags as $tag) {
			$tagArray[$tag['Tags']['id']] = $tag['Tags']['name'];
		}
		
		//get all tags currently assigned to the event
		$currentTags = $this->Template->TemplateTag->find('all', array(
			'recursive' => -1,
			'contain' => 'Tag',
			'conditions' => array('template_id' => $id),
		));
		$this->set('currentTags', $currentTags);
		$this->set('id', $id);
		$this->set('template', $template);
		$this->set('tags', $tagArray);
		$this->set('tagInfo', $tags);
	}
	
	public function view($id) {
		if (!$this->_isSiteAdmin() && !$this->Template->checkAuthorisation($id, $this->Auth->user(), false)) throw new MethodNotAllowedException('No template with the provided ID exists, or you are not authorised to see it.');
		if ($this->Template->checkAuthorisation($id, $this->Auth->user(), true)) $this->set('mayModify', true);
		else $this->set('mayModify', false);
		$template = $this->Template->find('first', array(
			'conditions' => array(
				'id' => $id,
			),
			'contain' => array(
				'TemplateElement',
				'TemplateTag' => array(
					'Tag',
				),
			),
		));
		if (empty($template)) throw new NotFoundException('No template with the provided ID exists, or you are not authorised to see it.');
		$tagArray = array();
		foreach($template['TemplateTag'] as $tt) {
			$tagArray[] = $tt;
		}
		$this->set('id', $id);
		$this->set('template', $template);
	}
	
	public function add() {
		if ($this->request->is('post')) {
			unset($this->request->data['Template']['tagsPusher']);
			$tags = $this->request->data['Template']['tags'];
			unset($this->request->data['Template']['tags']);
			$this->request->data['Template']['org'] = $this->Auth->user('org');
			$this->Template->create();
			if ($this->Template->save($this->request->data)) {
				$id = $this->Template->id;
				$tagArray = json_decode($tags);
				$this->loadModel('TemplateTag');
				$this->loadModel('Tag');
				foreach ($tagArray as $t) {
					$tag = $this->Tag->find('first', array(
						'conditions' => array('name' => $t),
						'fields' => array('id', 'name'),
						'recursive' => -1,
					));
					$this->TemplateTag->create();
					$this->TemplateTag->save(array('TemplateTag' => array('template_id' => $id, 'tag_id' => $tag['Tag']['id'])));
				}
				$this->redirect(array('action' => 'view', $this->Template->id));
			} else {
				throw new Exception('The template could not be created.');
			}
		}
		$this->loadModel('Tags');
		$tags = $this->Tags->find('all');
		$tagArray = array();
		foreach ($tags as $tag) {
			$tagArray[$tag['Tags']['id']] = $tag['Tags']['name'];
		}
		$this->set('tags', $tagArray);
		$this->set('tagInfo', $tags);
	}
	
	public function saveElementSorting() {
		// check if user can edit the template
		$this->autoRender = false;
		$this->request->onlyAllow('ajax');
		$orderedElements = $this->request->data;
		foreach($orderedElements as &$e) {
			$e = ltrim($e, 'id_');
		}
		$extractedIds = array();
		foreach ($orderedElements as $element) $extractedIds[] = $element;
		$template_id = $this->Template->TemplateElement->find('first', array(
			'conditions' => array('id' => $extractedIds),
			'recursive' => -1,
			'fields' => array('id', 'template_id'),
		));
		
		if (!$this->_isSiteAdmin() && !$this->Template->checkAuthorisation($template_id['TemplateElement']['template_id'], $this->Auth->user(), true)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'You are not authorised to do that.')), 'status' => 200));
		
		$elements = $this->Template->TemplateElement->find('all', array(
				'conditions' => array('template_id' => $template_id['TemplateElement']['template_id']),
				'recursive' => -1,
		));
		if (empty($elements)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Something went wrong, the supplied template elements don\'t exist, or you are not eligible to edit them.')),'status'=>200));
		if (count($elements) != count($orderedElements)) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Incomplete template element list passed as argument. Expecting ' . count($elements) . ' elements, only received positions for ' . count($orderedElements) . '.')),'status'=>200));
		$template_id = $elements[0]['TemplateElement']['template_id'];
		
		foreach ($elements as &$e) {
			if ($template_id !== $e['TemplateElement']['template_id']) return new CakeResponse(array('body'=> json_encode(array('saved' => false, 'errors' => 'Cannot sort template elements belonging to separate templates. You should never see this message during legitimate use.')),'status'=>200));
			foreach ($orderedElements as $k => $orderedElement) {
				if ($orderedElement == $e['TemplateElement']['id']) {
					$e['TemplateElement']['position'] = $k+1;
				}
			}
		}
		$this->Template->TemplateElement->saveMany($elements);
		return new CakeResponse(array('body'=> json_encode(array('saved' => true, 'success' => 'Elements repositioned.')),'status'=>200));
	}
	
	public function delete($id) {
		$template = $this->Template->checkAuthorisation($id, $this->Auth->user(), true);
		if (!$this->request->is('post')) throw new MethodNotAllowedException('This action can only be invoked via a post request.');
		if (!$this->_isSiteAdmin() && !$template) throw new MethodNotAllowedException('No template with the provided ID exists, or you are not authorised to edit it.');
		if ($this->Template->delete($id, true)) {
			$this->Session->setFlash('Template deleted.');
			$this->redirect(array('action' => 'index'));
		} else {
			$this->Session->setFlash('The template could not be deleted.');
			$this->redirect(array('action' => 'index'));
		}
	}
	

	public function templateChoices($id) {
		$this->loadModel('Event');
		$event = $this->Event->find('first' ,array(
				'conditions' => array('id' => $id),
				'recursive' => -1,
				'fields' => array('orgc', 'id'),
		));
		if (empty($event) || (!$this->_isSiteAdmin() && $event['Event']['orgc'] != $this->Auth->user('org'))) throw new NotFoundException('Event not found or you are not authorised to edit it.');
	
		$conditions = array();
		if (!$this->_isSiteAdmin) {
			$conditions['OR'] = array('Template.org' => $this->Auth->user('org'), 'Template.share' => true);
		}
		$templates = $this->Template->find('all', array(
				'recursive' => -1,
				'conditions' => $conditions
		));
		$this->set('templates', $templates);
		$this->set('id', $id);
		$this->render('ajax/template_choices');
	}
	
	public function populateEventFromTemplate($template_id, $event_id) {
		$template = $this->Template->find('first', array(
			'conditions' => array('Template.id' => $template_id),
			'contain' => array(
				'TemplateElement' => array(
					'TemplateElementAttribute',
					'TemplateElementText',
					'TemplateElementFile'	
				),
				'TemplateTag' => array(
					'Tag'
				)
			),
		));
		$this->loadModel('Event');
		$event = $this->Event->find('first', array(
			'conditions' => array('id' => $event_id),
			'recursive' => -1,
			'fields' => array('id', 'orgc', 'distribution'),
		));
		
		if (empty($event)) throw new MethodNotAllowedException('Event not found or you are not authorised to edit it.');
		if (empty($template)) throw new MethodNotAllowedException('Template not found or you are not authorised to edit it.');
		if (!$this->_isSiteAdmin()) {
			if ($event['Event']['orgc'] != $this->Auth->user('org')) throw new MethodNotAllowedException('Event not found or you are not authorised to edit it.');
			if ($template['Template']['org'] != $this->Auth->user('org') && !$template['Template']['share']) throw new MethodNotAllowedException('Template not found or you are not authorised to use it.');	
		}
		
		$this->set('template_id', $template_id);
		$this->set('event_id', $event_id);
		if ($this->request->is('post')) {
			$errors = array();
			$this->set('template', $this->request->data);
			$result = $this->Event->Attribute->checkTemplateAttributes($template, $this->request->data, $event_id, $event['Event']['distribution']);
			if (isset($this->request->data['Template']['modify']) || !empty($result['errors'])) {
				$fileArray = $this->request->data['Template']['fileArray'];
				$this->set('fileArray', $fileArray);
				$this->set('errors', $result['errors']);
				$this->set('templateData', $template);
				$this->set('validTypeGroups', $this->Event->Attribute->validTypeGroups);
			} else {
				$this->set('errors', $result['errors']);
				$this->set('attributes', $result['attributes']);
				$fileArray = $this->request->data['Template']['fileArray'];
				$this->set('fileArray', $fileArray);
				$this->set('distributionLevels', $this->Event->distributionLevels);
				$this->render('populate_event_from_template_attributes');
			}
		} else {
			$this->set('templateData', $template);
			$this->set('validTypeGroups', $this->Event->Attribute->validTypeGroups);
		}
	}
	
	public function submitEventPopulation($template_id, $event_id) {
		if ($this->request->is('post')) {
			$this->loadModel('Event');
			$event = $this->Event->find('first', array(
					'conditions' => array('id' => $event_id),
					'recursive' => -1,
					'fields' => array('id', 'orgc', 'distribution', 'published'),
					'contain' => 'EventTag',
			));
			if (empty($event)) throw new MethodNotAllowedException('Event not found or you are not authorised to edit it.');
			if (!$this->_isSiteAdmin()) {
				if ($event['Event']['orgc'] != $this->Auth->user('org')) throw new MethodNotAllowedException('Event not found or you are not authorised to edit it.');
			}

			$template = $this->Template->find('first', array(
					'id' => $template_id,
					'recursive' => -1,
					'contain' => 'TemplateTag',
					'fields' => 'id',
			));
			
			foreach ($template['TemplateTag'] as $tag) {
				$exists = false;
				foreach ($event['EventTag'] as $eventTag) {
					if ($eventTag['tag_id'] == $tag['tag_id']) $exists = true;
				}
				if (!$exists) {
					$this->Event->EventTag->create();
					$this->Event->EventTag->save(array('event_id' => $event_id, 'tag_id' => $tag['tag_id']));
				}
			}
			
			if (isset($this->request->data['Template']['attributes'])) {
				$attributes = unserialize($this->request->data['Template']['attributes']);
				$this->loadModel('Attribute');
				$fails = 0;
				foreach($attributes as $k => &$attribute) {
					if (isset($attribute['data'])) {
						$file = new File(APP . 'tmp/files/' . $attribute['data']);
						$content = $file->read();
						$attribute['data'] = base64_encode($content);
						$file->delete();
					}
					$this->Attribute->create();
					if (!$this->Attribute->save(array('Attribute' => $attribute))) $fails++;
				}
				$count = $k + 1;
				$event = $this->Event->find('first', array(
					'conditions' => array('Event.id' => $event_id),
					'recursive' => -1
				));
				$event['Event']['published'] = 0;
				$this->Event->save($event);
				if ($fails == 0) $this->Session->setFlash(__('Event populated, ' . $count . ' attributes successfully created.'));
				else $this->Session->setFlash(__('Event populated, but ' . $fails . ' attributes could not be saved.'));
				$this->redirect(array('controller' => 'events', 'action' => 'view', $event_id));
			} else { 
				throw new MethodNotAllowedException('No attributes submitted for creation.');
			}
		} else {
			throw new MethodNotAllowedException();
		}
	}
	
	public function uploadFile($elementId, $batch) {
		$this->layout = 'iframe';
		$this->set('batch', $batch);
		$this->set('element_id', $elementId);
		if ($this->request->is('get')) {
			$this->set('element_id', $elementId);
		} else if ($this->request->is('post')) {
			$fileArray = array();
			$filenames = array();
			$tmp_names = array();
			$element_ids = array();
			$result = array();
			$added = 0;
			$failed = 0;
			// filename checks
			foreach ($this->request->data['Template']['file'] as $k => $file) {
				if ($file['size'] > 0 && $file['error'] == 0) {
					if (preg_match('@^[\w\-. ]+$@', $file['name'])) {
						$fn = $this->Template->generateRandomFileName();
						move_uploaded_file($file['tmp_name'], APP . 'tmp/files/' . $fn);
						$filenames[] =$file['name'];
						$fileArray[] = array('filename' => $file['name'], 'tmp_name' => $fn, 'element_id' => $elementId);
						$added++;
					} else $failed++;
				} else $failed ++;
			}
			$result = $added . ' files uploaded.';
			if ($failed) {
				$result .= ' ' . $failed . ' files either failed to upload, or were empty.';
				$this->set('upload_error', true);
			} else {
				$this->set('upload_error', false);
			}
			
			$this->set('result', $result);
			$this->set('filenames', $filenames);
			$this->set('fileArray', json_encode($fileArray));
		}
	}
	
	private function __combineArrays($array, $array2) {
		foreach ($array2 as $element) {
			if (!in_array($element, $array)) {
				$array[] = $element;
			}
		}
		return $array;
	}

	public function deleteTemporaryFile($filename) {
		if (!$this->request->is('post')) throw new MethodNotAllowedException('This action is restricted to accepting POST requests only.');
		//if (!$this->request->is('ajax')) throw new MethodNotAllowedException('This action is only accessible through AJAX.');
		$this->autoRender = false;
		if (preg_match('/^[a-zA-Z0-9]{12}$/', $filename)) {
			$file = new File(APP . 'tmp/files/' . $filename);
			if ($file->exists()) {
				$file->delete();
			}
		}
	}
}