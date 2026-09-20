<?php

namespace App\Controller\Customer;

use App\Entity\CustomerUser;
use App\Service\AppSettings;
use App\Service\EmailNotifier;
use App\Validation\Constraint\ValidContactRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Validation;

final class ContactController extends AbstractCustomerController
{
    #[Route('/faq', name: 'customer_faq', methods: ['GET'])]
    public function faq(): Response
    {
        return $this->render('customer/contact/faq.html.twig');
    }

    #[Route('/contact', name: 'customer_contact', methods: ['GET', 'POST'])]
    public function index(
        Request $request,
        MailerInterface $mailer,
        AppSettings $appSettings,
        EmailNotifier $emailNotifier,
    ): Response {
        /** @var array<string, string> $values */
        $values = [
            'name' => '',
            'email' => '',
            'location' => '',
            'subject' => '',
            'comments' => '',
        ];

        $user = $this->getUser();
        if ($user instanceof CustomerUser) {
            $values['name'] = trim((string) (($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')));
            if ($values['name'] === '') {
                $values['name'] = (string) ($user->getCompany()?->getName() ?? '');
            }

            $values['email'] = (string) $user->getEmail();

            $locationParts = array_filter([
                $user->getCompany()?->getDefaultShippingAddress()?->getProvince(),
                $user->getCompany()?->getDefaultShippingAddress()?->getCity(),
            ], static fn (?string $value): bool => trim((string) $value) !== '');
            $values['location'] = implode(', ', $locationParts);
        }

        /** @var array<string, string> $errors */
        $errors = [];

        if ($request->isMethod('POST')) {
            $values['name'] = trim((string) $request->request->get('name', ''));
            $values['email'] = trim((string) $request->request->get('email', ''));
            $values['location'] = trim((string) $request->request->get('location', ''));
            $values['subject'] = trim((string) $request->request->get('subject', ''));
            $values['comments'] = trim((string) $request->request->get('comments', ''));

            $violations = Validation::createValidator()->validate(new \ArrayObject($values), new ValidContactRequest());
            foreach ($violations as $violation) {
                $errors[$violation->getPropertyPath()] = (string) $violation->getMessage();
            }

            if ($errors === []) {
                // Recipients, in order of how deliberately they were chosen. The last resort used to
                // be the literal 'admin@gmail.com' — a stranger's mailbox that every unconfigured
                // installation quietly forwarded its customers' contact messages to (#351). Active
                // admins are the honest fallback: they are real addresses belonging to this store.
                $to = [];
                foreach (['contact_email', 'app_email', 'support_email'] as $key) {
                    $configured = trim((string) $appSettings->get($key, ''));
                    if ($configured !== '') {
                        $to = [$configured];
                        break;
                    }
                }

                if ($to === []) {
                    $to = $emailNotifier->activeAdminEmails();
                }

                if ($to === []) {
                    $this->addFlash('error', 'Message could not be sent right now. Please try again.');

                    return $this->render('customer/contact/index.html.twig', [
                        'values' => $values,
                        'errors' => $errors,
                    ]);
                }

                // replyTo is the person who filled the form in, so staff can answer them straight
                // from their inbox. Deliberately not sender_replyto_address: that setting is for
                // mail this system sends *to* customers, and SenderReplyToSubscriber leaves any
                // message that already has a Reply-To alone precisely so this one survives (#474).
                $email = $appSettings->applyFromAddress(new Email(), AppSettings::FROM_SUPPORT)
                    ->to(...$to)
                    ->replyTo($values['email'])
                    ->subject('[Contact] ' . $values['subject'])
                    ->html($this->renderView('emails/contact_message.html.twig', [
                        'name' => $values['name'],
                        'email' => $values['email'],
                        'location' => $values['location'],
                        'subject' => $values['subject'],
                        'comments' => $values['comments'],
                    ]));

                try {
                    $mailer->send($email);
                } catch (\Throwable $e) {
                    $this->addFlash('error', 'Message could not be sent right now. Please try again.');
                    return $this->render('customer/contact/index.html.twig', [
                        'values' => $values,
                        'errors' => $errors,
                    ]);
                }

                $this->addFlash('success', 'Thanks! Your message has been sent.');
                return $this->redirectToRoute('customer_contact');
            }
        }

        return $this->render('customer/contact/index.html.twig', [
            'values' => $values,
            'errors' => $errors,
        ]);
    }
}
