<?php
if(class_exists('Conversation'))
	return;

require_once('boot.php');
require_once('object/BaseObject.php');
require_once('object/Item.php');
require_once('include/text.php');

/**
 * A list of threads
 *
 * We should think about making this a SPL Iterator
 */
class Conversation extends BaseObject {
	private $threads = array();
	private $mode = null;
	private $writeable = false;
	private $profile_owner = 0;

	public function __construct($mode) {
		$this->set_mode($mode);
	}

	/**
	 * Set the mode we'll be displayed on
	 */
	private function set_mode($mode) {
		if($this->get_mode() == $mode)
			return;

		$a = $this->get_app();

		switch($mode) {
			case 'network':
			case 'notes':
				$this->profile_owner = local_user();
				$this->writeable = true;
				break;
			case 'profile':
				$this->profile_owner = $a->profile['profile_uid'];
				$this->writeable = can_write_wall($a,$this->profile_owner);
				break;
			case 'display':
				$this->profile_owner = $a->profile['uid'];
				$this->writeable = can_write_wall($a,$this->profile_owner);
				break;
			default:
				logger('[ERROR] Conversation::set_mode : Unhandled mode ('. $mode .').', LOGGER_DEBUG);
				return false;
				break;
		}
		$this->mode = $mode;
	}

	/**
	 * Get mode
	 */
	public function get_mode() {
		return $this->mode;
	}

	/**
	 * Check if page is writeable
	 */
	public function is_writeable() {
		return $this->writeable;
	}

	/**
	 * Get profile owner
	 */
	public function get_profile_owner() {
		return $this->profile_owner;
	}

	/**
	 * Add a thread to the conversation
	 *
	 * Returns:
	 * 		_ The inserted item on success
	 * 		_ false on failure
	 */
	public function add_thread($item) {
		$item_id = $item->get_id();
		if(!$item_id) {
			logger('[ERROR] Conversation::add_thread : Item has no ID!!', LOGGER_DEBUG);
			return false;
		}
		if($this->get_thread($item->get_id())) {
			logger('[WARN] Conversation::add_thread : Thread already exists ('. $item->get_id() .').', LOGGER_DEBUG);
			return false;
		}
		$item->set_conversation($this);
		$this->threads[] = $item;
		return end($this->threads);
	}

	/**
	 * Get data in a form usable by a conversation template
	 *
	 * We should find a way to avoid using those arguments (at least most of them)
	 *
	 * Returns:
	 * 		_ The data requested on success
	 * 		_ false on failure
	 */
	public function get_template_data($cmnt_tpl, $alike, $dlike) {
		$result = array();

		foreach($this->threads as $item) {
			if($item->get_data_value('network') === NETWORK_MAIL && local_user() != $item->get_data_value('uid'))
				continue;
			$item_data = $item->get_template_data($cmnt_tpl, $alike, $dlike);
			if(!$item_data) {
				logger('[ERROR] Conversation::get_template_data : Failed to get item template data ('. $item->get_id() .').', LOGGER_DEBUG);
				return false;
			}
			$result[] = $item_data;
		}

		return $result;
	}

	/**
	 * Get a thread based on its item id
	 *
	 * Returns:
	 * 		_ The found item on success
	 * 		_ false on failure
	 */
	private function get_thread($id) {
		foreach($this->threads as $item) {
			if($item->get_id() == $id)
				return $item;
		}

		return false;
	}
}
?>
