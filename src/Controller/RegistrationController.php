<?php

namespace App\Controller;

use App\Entity\Registration;
use App\Entity\Transport;
use App\Form\RegistrationType;
use App\Form\ResetPasswordType;
use App\Form\ChangePasswordType;
use App\Repository\RegistrationsRepository;
use Doctrine\ORM\NonUniqueResultException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Translation\TranslatorInterface;

class RegistrationController extends AbstractController
{

    /**
     * @Route("/registration/edit", name="edit_registration")
     * @param Request $request
     * @return Response
     */
    public function edit(Request $request): Response
    {
        $registration = $this->getUser();

        if (!$registration) {
            throw $this->createNotFoundException(
                'No product found for id ' . $registration->getId()
            );
        }

        // 1) build the form
        $form = $this->createForm(RegistrationType::class, $registration);

        // 2) handle the submit (will only happen on POST)
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->getDoctrine()->getManager()->flush();
            if ($request->request->get('back')) {
                return $this->redirectToRoute('welcome');
            }
        }


        return $this->render('registration/registration_edit.html.twig', [
            'my_form' => $form->createView()
        ]);
    }

    /**
     * @Route("/registration/delete/{uid}", name="delete_registration")
     * @param $uid
     * @param RegistrationsRepository $registrationsRepository
     * @return Response
     * @throws NonUniqueResultException
     */
    public function delete($uid, RegistrationsRepository $registrationsRepository): Response
    {
        $em = $this->getDoctrine()->getManager();

        $registration = $registrationsRepository->findOneById($uid);
        $em->remove($registration);
        $em->flush();

        return $this->redirectToRoute('admin');
    }

    /**
     * @Route("/registration/send_confirmation/{uid}", name="confirm_registration")
     * @param RegistrationsRepository $registrationsRepository
     * @param $uid
     * @param \Swift_Mailer $mailer
     * @param TranslatorInterface $translator
     * @return Response
     * @throws NonUniqueResultException
     */
    public function confirm(RegistrationsRepository $registrationsRepository, $uid, \Swift_Mailer $mailer, TranslatorInterface $translator): Response
    {
        $registration = $registrationsRepository->findOneById($uid);

        if (!$registration) {
            throw $this->createNotFoundException(
                'No registration found for id ' . $uid
            );
        }

        $arrival = $registration->getTransports()->filter(function (Transport $transport) {
            return $transport->getIsArrival();
        })->first();
        $departure = $registration->getTransports()->filter(function (Transport $transport) {
            return !$transport->getIsArrival();
        })->first();

        $now = new \DateTime();

        $body = $this->renderView('emails/confirmation.html.twig', [
            'registration' => $registration,
            'now' => $now,
            'arrival' => $arrival,
            'departure' => $departure,
        ]);

        $message = (new \Swift_Message())
            ->setSubject($translator->trans('title.confirmation') . ' ' . $registration->getClub() . '(' . $now->format('Y-m-d H:i:s') . ')')
            ->setFrom(['anmeldung@thueringer-judoverband.de' => 'ITP Registration'])
            ->setTo($registration->getEmail())
            ->setCc(['anmeldung@thueringer-judoverband.de' => 'ITP Registration'])
            ->setBody($body, 'text/html');

        $mailer->send($message);

        return new Response($body);
    }

    /**
     * @Route("/registration/change_password", name="change_password")
     * @param Request $request
     * @param AuthenticationUtils $authenticationUtils
     * @param UserPasswordEncoderInterface $passwordEncoder
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function changePassword(Request $request, AuthenticationUtils $authenticationUtils, UserPasswordEncoderInterface $passwordEncoder): Response
    {
        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        $registration = $this->getUser();

        if (!$registration) {
            throw $this->createNotFoundException(
                'No user'
            );
        }

        // 1) build the form
        $form = $this->createForm(ChangePasswordType::class, $registration);

        // 2) handle the submit (will only happen on POST)
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            // 3) Encode the password (you could also do this via Doctrine listener)
            $password = $passwordEncoder->encodePassword($registration, $registration->getPlainPassword());
            $registration->setPassword($password);

            // 4) update the User!
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->flush();

            $token = new UsernamePasswordToken(
                $registration,
                $password,
                'main',
                $registration->getRoles()
            );

            $this->get('security.token_storage')->setToken($token);
            $this->get('session')->set('_security_main', serialize($token));

            $this->addFlash('success', 'You have successfully changed your password!');
        }

        return $this->render('registration/change_password.html.twig', ['my_form' => $form->createView(),
            'error' => $error,]);
    }

    /**
     * @Route("/reset_password/{uid}/{hash}", name="reset_password", requirements={"uid"="\d+"})
     * @param Request $request
     * @param $uid
     * @param $hash
     * @param RegistrationsRepository $registrationsRepository
     * @param UserPasswordEncoderInterface $passwordEncoder
     * @return Response
     * @throws NonUniqueResultException
     */
    public
    function resetPassword(Request $request, $uid, $hash, RegistrationsRepository $registrationsRepository, UserPasswordEncoderInterface $passwordEncoder): Response
    {
        $registration = $registrationsRepository->findOneById($uid);

        if ($registration) {
            $re_hash = hash('sha256', $registration->getPassword(), false);

            if ($hash !== $re_hash) {
                throw $this->createNotFoundException(
                    'Sorry, something went wrong. Please contact us if this error persists.'
                );
            }
        } else {
            throw $this->createNotFoundException(
                "Sorry, this club doesn't exist"
            );
        }

        // 1) build the form
        $form = $this->createForm(ResetPasswordType::class, $registration);

        // 2) handle the submit (will only happen on POST)
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            // 3) Encode the password (you could also do this via Doctrine listener)
            $password = $passwordEncoder->encodePassword($registration, $registration->getPlainPassword());
            $registration->setPassword($password);

            // 4) save the User!
            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->flush();

            $token = new UsernamePasswordToken(
                $registration,
                $password,
                'main',
                $registration->getRoles()
            );

            $this->get('security.token_storage')->setToken($token);
            $this->get('session')->set('_security_main', serialize($token));

            $this->addFlash('success', 'You have successfully reset your password!');

            return $this->redirectToRoute('welcome');
        }

        return $this->render('security/reset_password.html.twig', [
            'my_form' => $form->createView()
        ]);
    }

    /**
     * @Route("/registration", name="registration")
     * @param Request $request
     * @param UserPasswordEncoderInterface $passwordEncoder
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public
    function register(Request $request, UserPasswordEncoderInterface $passwordEncoder)
    {
        $registration = new Registration();

        // 1) build the form
        $form = $this->createForm(RegistrationType::class, $registration);
        $form->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => ['label' => 'Password', 'always_empty' => true],
            'second_options' => ['label' => 'Repeat Password', 'always_empty' => true],
        ]);
        $form->get('country')->setData('DE');

        // 2) handle the submit (will only happen on POST)
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {

            // 3) Encode the password (you could also do this via Doctrine listener)
            $password = $passwordEncoder->encodePassword($registration, $registration->getPlainPassword());
            $registration->setPassword($password);

            // 4) save the User!
            $entityManager = $this->getDoctrine()->getManager();
            $registration->setTimestamp(new \DateTime());
            $entityManager->persist($registration);
            $entityManager->flush();

            $token = new UsernamePasswordToken(
                $registration,
                $password,
                'main',
                $registration->getRoles()
            );

            $this->get('security.token_storage')->setToken($token);
            $this->get('session')->set('_security_main', serialize($token));

            $this->addFlash('success', 'You are now successfully registered!');

            return $this->redirectToRoute('welcome');
        }


        return $this->render('registration/registration.html.twig', [
            'my_form' => $form->createView()
        ]);
    }
}
