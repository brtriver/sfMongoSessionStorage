<?php
/**
 * sfMongoSessionStorage manages session storage via MongoDB
 *
 * This class stores the session data in via MongoDB and with an id issued in a
 * signed cookie. Useful when you don't want to store the session.
 *
 * @package    symfony
 * @subpackage storage
 * @author     Masao Maeda <brt.river@gmail.com>
  */
class sfMongoSessionStorage extends sfSessionStorage
{
  protected
    $host = null,
    $port = null,
    $db = null,
    $col = null;

  /**
   * Available options:
   *
   *   * db_table:    The database table in which session data will be stored
   *   * database:    The sfDatabase object to use
   *   * db_id_col:   The database column in which the session id will be stored (sess_id by default)
   *   * db_data_col: The database column in which the session data will be stored (sess_data by default)
   *   * db_time_col: The database column in which the session timestamp will be stored (sess_time by default)
   *
   * @param  array $options  An associative array of options
   *
   * @see sfSessionStorage
   */
  public function initialize($options = array())
  {
    $options = array_merge(array(
      'db_id_col'   => 'sess_id',
      'db_data_col' => 'sess_data',
      'db_time_col' => 'sess_time',
      'host' => 'localhost',
      'port' => '27017',
    ), $options);

    // disable auto_start
    $options['auto_start'] = false;

    // initialize the parent
    parent::initialize($options);

    if (!isset($this->options['db_name']))
    {
      throw new sfInitializationException('You must provide a "db_name" option to sfMongoSessionStorage.');
    }

    if (!isset($this->options['collection_name']))
    {
      throw new sfInitializationException('You must provide a "collection_name" option to sfMongoSessionStorage.');
    }

    // use this object as the session handler
    session_set_save_handler(array($this, 'sessionOpen'),
                             array($this, 'sessionClose'),
                             array($this, 'sessionRead'),
                             array($this, 'sessionWrite'),
                             array($this, 'sessionDestroy'),
                             array($this, 'sessionGC'));

    // start our session
    session_start();
  }

  /**
   * Closes a session.
   *
   * @return boolean true, if the session was closed, otherwise false
   */
  public function sessionClose()
  {
    // do nothing
    return true;
  }

  /**
   * Opens a session.
   *
   * @param  string $path  (ignored)
   * @param  string $name  (ignored)
   *
   * @return boolean true, if the session was opened, otherwise an exception is thrown
   *
   * @throws <b>DatabaseException</b> If a connection with the database does not exist or cannot be created
   */
  public function sessionOpen($path = null, $name = null)
  {
    // what host and port are we using?
    $host = $this->options['host'];
    $port = $this->options['port'];

    // what database are we using?
    $db_name = $this->options['db_name'];

    if (!class_exists('Mongo'))
    {
      throw new sfInitializationException('Mongo class is not exists!');
    }
    $mongo = new Mongo(sprintf("%s:%s", $host, $port));
    $this->db = $mongo->selectDB($db_name);
    $this->col = $this->db->selectCollection($this->options['collection_name']);
    if (null === $this->db && null === $this->col)
    {
      throw new sfDatabaseException('MongoDB connection does not exist. Unable to open session.');
    }
    return true;
  }

  /**
   * Destroys a session.
   *
   * @param  string $id  A session ID
   *
   * @return bool true, if the session was destroyed, otherwise an exception is thrown
   *
   * @throws <b>sfException</b> If the session cannot be destroyed
   */
  public function sessionDestroy($id) {
    if ($this->col->remove(array($this->options['db_id_col'] => $id)))
    {
      return true;
    }
    $last_error = $this->db->lastError();
    throw new sfException(sprintf('%s cannot destroy session id "%s" (%s).', get_class($this), $id, $last_error['err']));
  }

  /**
   * Cleans up old sessions.
   *
   * @param  int $lifetime  The lifetime of a session
   *
   * @return bool true, if old sessions have been cleaned, otherwise an exception is thrown
   *
   * @throws <b>sfException</b> If any old sessions cannot be cleaned
   */
  public function sessionGC($lifetime)
  {
    // get column
    $db_time_col = $this->options['db_time_col'];

    // delete the record older than the authorised session life time 
    if ($this->col->remove(array('$where' => sprintf('this.%s + %d < %d', $db_time_col, $lifetime, time()))))
    {
      return true;
    }
    $last_error = $this->db->lastError();
    throw new sfException(sprintf('%s cannot delete old sessions (%s).', get_class($this), $last_error['err']));
  }


  /**
   * Reads a session.
   *
   * @param  string $id  A session ID
   *
   * @return bool true, if the session was read, otherwise an exception is thrown
   *
   * @throws <b>sfException</b> If the session cannot be read
   */
  public function sessionRead($id)
  {
    // get column
    $db_data_col = $this->options['db_data_col'];
    $db_id_col   = $this->options['db_id_col'];
    $db_time_col = $this->options['db_time_col'];

    $obj = $this->col->findOne(array($this->options['db_id_col'] => $id));
    if (count($obj))
    {
      // found the session
      return $obj[$db_data_col];
    }
    else
    {
      $obj = array(
        $db_id_col => $id,
        $db_data_col => '',
        $db_time_col => time(),
        );
      // session does not exist, create it
      if ($this->col->insert($obj))
      {
        //        $this->col->ensureIndex(array($db_id_col => 1));
        return '';
      }

      // can't create record
      $last_error = $this->db->lastError();
      throw new sfException(sprintf('%s cannot create new record for id "%s" (%s).', get_class($this), $id, $last_error['err']));
    }
  }
  /**
   * Writes session data.
   *
   * @param  string $id    A session ID
   * @param  string $data  A serialized chunk of session data
   *
   * @return bool true, if the session was written, otherwise an exception is thrown
   *
   * @throws <b>sfException</b> If the session data cannot be written
   */
  public function sessionWrite($id, $data)
  {
    // get column
    $db_data_col = $this->options['db_data_col'];
    $db_id_col   = $this->options['db_id_col'];
    $db_time_col = $this->options['db_time_col'];

    // update the record associated with this id
    $obj = array(
      $db_id_col => $id,
      $db_data_col => $data,
      $db_time_col => time(),
      );
    if ($this->col->update(array($db_id_col => $id), $obj))
    {
      return true;
    }
    // failed to write session data
    $last_error = $this->db->lastError();
    throw new sfException(sprintf('%s cannot write session data for id "%s" (%s).', get_class($this), $id, $last_error['err']));
  }

  /**
   * Regenerates id that represents this storage.
   *
   * @param  boolean $destroy Destroy session when regenerating?
   *
   * @return boolean True if session regenerated, false if error
   *
   */
  public function regenerate($destroy = false)
  {
    if (self::$sessionIdRegenerated)
    {
      return;
    }

    $currentId = session_id();

    parent::regenerate($destroy);

    $newId = session_id();
    $this->sessionRead($newId);

    return $this->sessionWrite($newId, $this->sessionRead($currentId));
  }

  /**
   * Executes the shutdown procedure.
   *
   */
  public function shutdown()
  {
    parent::shutdown();
  }
}
