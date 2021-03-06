<?php
/**
 * Make the linke between Exam and User, through Records
 * @package Unsapa\IPWBundle\Controller
 */

namespace Unsapa\IPWBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;

use Unsapa\IPWBundle\Helper\ExamCheckHelper;
use Unsapa\IPWBundle\Entity\Record;
use Unsapa\IPWBundle\Entity\Exam;
use Unsapa\IPWBundle\Entity\User;
use Unsapa\IPWBundle\Entity\Promo;

/**
 * Manage how students attend to the exams
 */
class AttendController extends Controller
{
  /**
   * When the form is posted, get the data and compare them to the database
   *
   * @param integer $id ID of the current exam
   */
  protected function examAttributeStudents($id)
  {
    $exam = $this->getDoctrine()->getRepository("UnsapaIPWBundle:Exam")->find($id);
    $promo_users = $this->getDoctrine()->getRepository("UnsapaIPWBundle:User")->findByPromo($exam->getPromo());

    $students_id = $this->getRequest()->request->all();
    $selected_users = array();
    foreach($students_id as $student_id)
    {
      $user = $this->getDoctrine()->getRepository("UnsapaIPWBundle:User")->find($student_id);
      if($user->getPromo() != $exam->getPromo())
        throw new AccessDeniedHttpException("Vous n'avez pas manipuler cet utilisateur pour cet examen.");

      array_push($selected_users, $user);
    }
    $unselected_users = array_diff($promo_users, $selected_users);

    // We delete the corresponding records (students who don't attend anymore to the exam)
    $em = $this->getDoctrine()->getEntityManager();
    foreach($unselected_users as $user)
    {
      $record = $em
        ->createQuery("SELECT r FROM UnsapaIPWBundle:Record r WHERE r.exam = :exam AND r.student = :user")
        ->setParameters(array('exam' => $exam, 'user' =>$user))
        ->setMaxResults(1)
        ->getResult();
      if(count($record) == 1)
      {
        $em->remove($record[0]);
        $em->flush();
      }
    }
    // We add a new record to the users who didn't have one
    foreach($selected_users as $user)
    {
      $record = $em
        ->createQuery("SELECT r FROM UnsapaIPWBundle:Record r WHERE r.exam = :exam AND r.student = :user")
        ->setParameters(array('exam' => $exam, 'user' =>$user))
        ->setMaxResults(1)
        ->getResult();
      if(count($record) == 0)
      {
        $record = new Record();
        $record->setExam($exam);
        $record->setStudent($user);
        $em->persist($record);
        $em->flush();
      }
    }
  }

  /**
   * Manage the students who attend an exam 
   * @param integer $id ID of the current exam
   * Route : /exam/:id/students
   */
  public function examChoiceAction($id)
  {
    $exam = $this->getDoctrine()->getRepository("UnsapaIPWBundle:Exam")->find($id);
    $user = $this->get('security.context')->getToken()->getUser();
    ExamCheckHelper::securityCheckExam($exam, $user);

    if($this->getRequest()->getMethod() == "POST")
    {
      $this->examAttributeStudents($id);
      return $this->redirect($this->generateUrl('exams'), 301);
    }

    $records = $exam->getRecords();

    $promo_users = $this->getDoctrine()->getRepository("UnsapaIPWBundle:User")->findByPromo($exam->getPromo());
    $exam_users = array();
    foreach($records as $record)
    {
      array_push($exam_users, $record->getStudent());
    }
    $not_exam_users = array_diff($promo_users, $exam_users);

    $records = $records->filter(function($r)
    {
      return ($r->getDocument() != NULL);
    });

    return $this->render("UnsapaIPWBundle:Attend:choice.html.twig", 
      array('exam_users' => $exam_users, 'not_exam_users' => $not_exam_users, 'exam' => $exam, 'records' => $records));
  }

  /**
   * When a user post /exams/:id/mark we modify the mark of the students
   *
   * @param $exam concerned exam
   * @return array of Record
   */
  protected function markStudents($exam)
  {
    $student_ids = $this->getRequest()->request->keys();
    $em = $this->getDoctrine()->getEntityManager();
    $invalid_records = array();
    for($i = 0; $i < count($student_ids); $i++)
    {
      $record = $this->getDoctrine()->getRepository("UnsapaIPWBundle:Record")->findByExamAndStudentId($exam->getId(), $student_ids[$i]);

      if($record === NULL)
        throw $this->createNotFoundException("Étudiant inexistant.");

      $mark = floatval($this->getRequest()->request->get($student_ids[$i]));
      $unpersistant_record = new Record();
      $unpersistant_record->setExam($record->getExam());
      $unpersistant_record->setStudent($record->getStudent());
      $unpersistant_record->setDocument($record->getDocument());
      $unpersistant_record->setMark($record->getMark());

      if(empty($mark))
        $unpersistant_record->setMark(NULL);
      else
        $unpersistant_record->setMark($mark);

      $validator = $this->get('validator');
      $errors = $validator->validate($unpersistant_record);
      if(count($errors) > 0)
      {
        array_push($invalid_records, $record);
      }
      else
      {
        $record->setMark($unpersistant_record->getMark());
        $em->persist($record);
        $em->flush();
      }
    }
    return $invalid_records;
  }

  /**
   * Manage the students who attend an exam 
   * @param integer $id ID of the current exam
   * Route : /exam/:id/students
   */
  public function markAction($id)
  {
    $exam = $this->getDoctrine()->getRepository("UnsapaIPWBundle:Exam")->find($id);
    $user = $this->get('security.context')->getToken()->getUser();
    $invalid_records = array();

    ExamCheckHelper::securityCheckExam($exam, $user, __FUNCTION__);

    if($this->getRequest()->getMethod() == "POST")
      $invalid_records = $this->markStudents($exam);

    $records_document = $this->getDoctrine()->getEntityManager()
      ->createQuery("SELECT r FROM UnsapaIPWBundle:Record r WHERE r.exam = :exam AND r.document IS NOT NULL")
      ->setParameter('exam', $exam)
      ->getResult();
    $records_empty = $this->getDoctrine()->getEntityManager()
      ->createQuery("SELECT r FROM UnsapaIPWBundle:Record r WHERE r.exam = :exam AND r.document IS NULL")
      ->setParameter('exam', $exam)
      ->getResult();
    
    return $this->render("UnsapaIPWBundle:Attend:mark.html.twig", 
      array('records_document' => $records_document, 'records_empty' => $records_empty,
            'exam' => $exam, 'invalid_records' => $invalid_records)); 
  }

  /**
   * Download the file uploaded by the user 
   * @param integer $userid parameter of the record id
   * @param integer $examid 2nd parameter of the record id
   * Route : /download/:userid/:examid
   */
  public function downloadAction($userid, $examid)
  {
    $current_user = $this->get('security.context')->getToken()->getUser();
    $record = $this->getDoctrine()->getRepository("UnsapaIPWBundle:Record")->findByExamAndStudentId($examid, $userid);
    if(!$current_user || !$record || ($userid != $current_user->getId() && $current_user != $record->getExam()->getResp()))
      throw $this->createNotFoundException("Fichier inconnu");

    $filename = $record->getDocumentAbsolutePath();
    $record->setFile(new File($filename));

    $r = new Response();
    $r->setStatusCode(200);
    $r->headers->set('Content-Type', $record->getFile()->getMimeType());
    $r->headers->set('Content-Transfer-Encoding', 'binary');
    $r->headers->set('Content-Disposition', 'attachment; filename="' 
      . $record->getStudent()->getFirstName()
      . $record->getStudent()->getLastName()
      . $record->getExam()->getTitle() . "."
      . $record->getFile()->getExtension() . '"'
    );
    $r->headers->set('Content-Length', filesize($filename));
    $r->setContent(file_get_contents($filename));
    $r->send();

    return $r;
  }
}
?>
