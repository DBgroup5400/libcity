<?php
require_once "libdb.php";

class City extends db{
  /* constructor */
  public function __construct( $__host, $__user, $__passwd ){
    parent::__construct( $__host, $__user, $__passwd );
  }
  /* end of constructor */

  /*****************************************
    区の名前から区のIDに変換します
    $name 区のID
  ****************************************/
  public function NAME_2_ID( $name ){
    $query = sprintf( "SELECT * FROM Neighbor.City_ID WHERE City_Name = '%s'",$name );
    $result = $this->_db_throw_query( 'Neighbor', $query );
    // 結果のチェック
    // MySQL に送られたクエリと返ってきたエラーをそのまま表示します。デバッグに便利です。
    if (!$result) {
      $message  = 'Invalid query: ' . mysqli_error() . "\n";
      $message .= 'Whole query: ' . $query;
      return NULL;
    }
    $data = mysqli_fetch_array( $result );

    return $data['City_ID'];      
  }
  /********************************
    区のIDから名前に変換します
    $ID 区のID
  ********************************/
  public function ID_2_NAME($ID){
    $query = sprintf("SELECT City_Name FROM City_ID WHERE City_ID = '%s' ",$ID);
    $result = $this->_db_throw_query( 'Neighbor', $query );
    // 結果のチェック
    // MySQLi に送られたクエリと返ってきたエラーをそのまま表示します。デバッグに便利です。
    if(!$result){
      $message  = 'Invalid query: ' . mysqli_error() . "\n";
      $message .= 'Whole query: ' . $query;
      return NULL;
    }
    $data = mysqli_fetch_array( $result );

    return $data['City_Name'];
  }
  /*****************************************
    ユーザーIDから住んでいる区を出します
    $USER_ID
  *****************************************/
  public function USERID_2_CITY($USR_ID){
    $query = sprintf("SELECT City_ID FROM User_Geo WHERE User_ID = '%s'; ",$USR_ID);
    $result = $this->_db_throw_query( 'Users_Geo', $query );
    if(!$result){
      $message  = 'Invalid query: ' . mysqli_error() . "\n";
      $message .= 'Whole query: ' . $query;
      return NULL;
    }

    $data = mysqli_fetch_array($result);
    return $data['City_ID'];
  }
  /*****************************************************************************
    ある区に隣接する区のIDをリストアップ
    ＄ID 区のID
  *****************************************************************************/
  public function NEIGHBOR($ID){
    $query = sprintf(" SELECT * FROM  Neighbor.Neighbors WHERE City = '%d'; ",$ID);
    $result = $this->_db_throw_query( 'Neighbor', $query );
    if(!$result){
      $message  = 'Invalid query: ' . mysqli_error() . "\n";
      $message .= 'Whole query: ' . $query;
      return NULL;
    }

    $data = mysqli_fetch_array($result);
    return $data;
  }
  /*****************************************************
    ユーザーAの位置とユーザーBの位置の距離を検索する。 
    $lat1 ユーザAの緯度
    $lng1 ユーザAの経度 
    $lat1 ユーザBの緯度
    $lng1 ユーザBの経度 
  *****************************************************/
  public function getPointsDistance($lat1, $lng1, $lat2, $lng2){
     $pi1 = pi();
     $lat1 = $lat1*$pi1/180;
     $lng1 = $lng1*$pi1/180;
     $lat2 = $lat2*$pi1/180;
     $lng2 = $lng2*$pi1/180;
     $deg = sin($lat1)*sin($lat2) + cos($lat1)*cos($lat2)*cos($lng2-$lng1);
     return round(6378140*(atan2(-$deg,sqrt(-$deg*$deg+1))+$pi1/2), 0)/1000.0;
  }
  /*********************************************************
  ユーザーのIDから近隣に住んでいるユーザーをリストアップ
  $ID  ユーザーID
*********************************************************/
  public function negibhor_list($ID){
    $users = array();
    $city =  $this->USERID_2_CITY($ID);
    $neighbor = $this->NEIGHBOR($city);

    $query = "SELECT * FROM Users_Geo.User_Geo WHERE ";
    
    $i = 1;

    $get = sprintf("City_ID = '%s'", $neighbor[0]);

    $query = $query.$get;

    //listing negighbors city query
    while($neighbor[$i] != NULL){
      $get = sprintf("OR City_ID = '%s'", $neighbor[$i]);
      $query = $query.$get;
      $i++;
    }

    $result  = $this->_db_throw_query( 'Users_Geo', $query );

    $cnt = 0; // array counter
    while( ($data = mysqli_fetch_assoc($result) )  != NULL){
      $users[$cnt] = array('ID'=> $data['User_ID'],'POS_X' => $data['Pos_X'], 'POS_Y' => $data['Pos_Y'], 'DIS' => 0); // copy result to array
      $cnt++;
    }

    sprintf($query,"SELECT Pos_X ,Pos_Y FROM Users_Geo.User_Geo WHERE User_ID = %d",$ID);   // get sercher address
    $result  = $this->_db_throw_query( 'Users_Geo', $query );
    $data = mysqli_fetch_array($result);

    $user_x = $data['Pos_X'];
    $user_y = $data['Pos_Y'];

    $cnt = 0;

    foreach( $users as &$each){
      $dist = $this->getPointsDistance( $user_x, $user_y, $each['POS_X'] ,$each['POS_Y']);
      $each['DIS'] = $dist;
      $cnt++;
    }

    // foreach($users as $key => $row){
    //     $DIS[$key] = $row['DIS'];
    // }
    // array_multisort($DIS,SORT_ASC,$users); //sort by distance

    return $users;
  }
  /************************************************************************** 
    ユーザーから一定距離に住んでいるユーザーの買い物履歴から価格を予測します.
    $round 検索範囲
    $ID 検索者ID
    $list 検索したい食品のIDリスト　
  ***************************************************************************/
  public function SerchPrice($round, $ID, $list){
    $users = $this->negibhor_list($ID);
    // print_r( $users );
    
    $query = 0;
    $i = 0;
    $result = 0;
    foreach ($list as &$food){
      $query = NULL;
      foreach($users as &$private){
        if($private['DIS'] < $round){
          if($query != NULL)  $query = $query." UNION ALL ";
          $tmp = sprintf("SELECT * FROM  U%s WHERE ID  = '%s'",$private['ID'],$food);
          $query = $query.$tmp;
        }
      }
      $sum = 0;
      $num = 0;

      $result = ( $query )?( $this->_db_throw_query( 'Users_Geo', $query ) ):( 0 );
      if( $result != NULL ){
        while(( $data = mysqli_fetch_assoc($result) ) != NULL){
          $num += 1;
          $sum += $data['Price'];
        }
      }

      if($num == 0){
        $query = sprintf("SELECT * FROM  UXXXXXX WHERE ID = '%s'",$food);
        $result = $this->_db_throw_query( "Users_Geo", $query );
        $data = mysqli_fetch_assoc( $result );
        $list[$i] = $data["Price"];
      }else{
        $list[$i] = $sum / $num;
      } 
      $i++;
    }
    return $list; 
  }

  /* destructor */
  public function __destruct(){
    parent::__destruct();
  }
  /* end of destructor */
}
?>