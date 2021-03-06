<?php
/**
 * Created by PhpStorm.
 * User: davin.bao
 * Date: 14-5-5
 * Time: 上午9:58
 */
namespace DavinBao\Workflow;

use Symfony\Component\Process\Exception\InvalidArgumentException;
use Config;

trait HasFlowForResource
{

  public $isBinding = true;
  /**
   * Many-to-Many relations with Node
   */
  public function resourceflow()
  {
    return $this->morphOne('DavinBao\Workflow\WorkFlowResourceflow', 'resource');
  }

  public function getFlows($resource_type){
    return WorkFlowFlow::where('resource_type','=',$resource_type)->get();
  }

  public function isBindingFlow() {
    return $this->resourceflow != null;
  }

  public function bindingFlow($flow_id){
    $resFlows = WorkFlowResourceflow::where('flow_id','=',$flow_id)->where('resource_id','=',$this->id)->get();
    if(!$resFlows || $resFlows->count()<=0){
      $resFlow = new WorkFlowResourceflow();
      $resFlow->flow_id = $flow_id;
      $resFlow->resource_type = WorkFlowFlow::find($flow_id)->resource_type;
      $resFlow->resource_id = $this->id;
      $resFlow->save();
    }
  }

  public function startFlow($auditUsers, $title, $content){
      if(!$this->resourceflow) return false;
    $this->resourceflow->goFirst();
    return $this->agree('',$auditUsers, $title, $content);
  }

  public function status(){
      if(!$this->resourceflow) return false;
    return $this->resourceflow->status;
  }

  public function flow(){
      if(!$this->resourceflow) return false;
      return $this->resourceflow->flow()->first();
  }

  public function orderID(){
      if(!$this->resourceflow) return false;
    return $this->resourceflow->node_orders;
  }

  /**
   * Get all audit users has role in current node of this flow
   * @return array $user
   */
  public function getNextAuditUsers(){
      if(!$this->resourceflow) return false;
    return $this->resourceflow->getNextAuditUsers();
  }

    public function getNextNode(){
        if(!$this->resourceflow) return false;
        return $this->resourceflow->getNextNode();
    }

    public function getCurrentNode(){
        if(!$this->resourceflow) return false;
      return $this->resourceflow->getCurrentNode();
    }

    public function isFirstAudit(){
        if($this->resourceflow->status == 'unstart' && $this->resourceflow->resourcenodes()->count()<=0){
            return true;
        }
        return false;
    }

    public function isMeAudit(){
        if(!$this->resourceflow) return false;
        $myNode = $this->resourceflow->getMyUnAuditResourceNode();
        if($myNode !== null){
            return true;
        }
        if($this->isFirstAudit() && $this->create_user_id == \Auth::user()->id){
            return true;
        }

        //下一节点，是否需要我来签字
//        $cnode = $this->resourceflow->getCurrentNode();
//        if($cnode){
//            return $cnode->isContainsMe();
//        }
        return false;
    }

  public function agree($comment, $auditUsers, $title = null, $content = null){
    if(!$this->resourceflow) return false;
    //如果是创建时审批
    if($this->isFirstAudit()){
        //设置未审批人为创建人
        $this->resourceflow->setNextAuditUsers(array($this->createUser));
    }

    if($this->resourceflow->comment('agreed',$comment, $title, $content)) {
      //go next node
      //if have not next resource node ,to go next node
      $unauditedNode = $this->resourceflow->getAnotherUnAuditResourceNode();
      if(!$unauditedNode || $unauditedNode->count()<=0){
        $this->resourceflow->goNext();
      }

      if($auditUsers && $auditUsers->count()>0) {
        $this->resourceflow->setNextAuditUsers($auditUsers);
      }
      return true;
    }

    return false;
  }

  public function disagree($callback, $comment, $title = null, $content = null){
    if($this->status() != 'proceed') return false;
      if(!$this->resourceflow) return false;
    if($this->resourceflow->comment('disagreed',$comment, $title, $content)) {
      //run callback
      if ($callback) {
        return call_user_func($callback);
      }
    }

    return false;
  }

  public function shouldPublish(){
      if(!$this->resourceflow) return false;
    if($this->resourceflow->getAnotherUnAuditResourceNode() == false && $this->resourceflow->getNextNode() == null){
      return true;
    }
    return false;
  }

  public function discard(){
      if(!$this->resourceflow) return false;
    return $this->resourceflow->discard();
  }

  public function goFirst(){
      if(!$this->resourceflow) return false;
    return $this->resourceflow->goFirst();
  }

  /**
   * Before delete all constrained foreign relations
   *
   * @param bool $forced
   * @return bool
   */
  public function beforeDelete( $forced = false )
  {
    try {
        if($this->resourceflow){
            $this->resourceflow->delete();
        }
    } catch(Execption $e) {}

    return true;
  }

  public function getAuditByTimeLine(){
    $auditsByDate = array();
    $flow = $this->flow();
      if(!$this->resourceflow) return false;
    $nodes = $this->resourceflow->resourcenodes()->get();

    foreach ($nodes as $node) {
      $username = $node->user()->first()->name();

      $nodename = \Lang::get('workflow::workflow.push');
      if((int)$node->orders>0){
        $nodename = $flow->nodes()->where('orders','=',$node->orders)->first()->node_name;
      }

      $auditsByDate[$node->updated_at->toDateString()][$node->id]['id'] = $node->id;
      $auditsByDate[$node->updated_at->toDateString()][$node->id]['username'] = $username;
      $auditsByDate[$node->updated_at->toDateString()][$node->id]['nodename'] = $nodename;
      $auditsByDate[$node->updated_at->toDateString()][$node->id]['result'] = $node->result;
      $auditsByDate[$node->updated_at->toDateString()][$node->id]['comment'] = $node->comment;
      $auditsByDate[$node->updated_at->toDateString()][$node->id]['updated_at'] = $node->updated_at->format('H:i');
    }
    return $auditsByDate;
  }

    public static function getCompletedList(){
        $cModel = get_called_class();

        return $cModel::whereHas('resourceflow', function($query)
        {
            $query->where('status', '=', 'completed');
        });
    }

    public static function getAuditList(){
        $cModel = get_called_class();

        return $cModel::whereHas('resourceflow', function($query)
        {
            $query->whereIn('status', array('proceed','unstart'));
        });
    }

    public function lastIsMe(){
        $lastUser = $this->resourceflow->getLastAuditUser();
        if($lastUser === null) return false;
        return $lastUser->id == \Auth::user()->id;
    }
}
