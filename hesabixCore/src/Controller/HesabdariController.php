<?php

namespace App\Controller;

use App\Entity\BankAccount;
use App\Entity\Business;
use App\Entity\Cashdesk;
use App\Entity\Cheque;
use App\Entity\Commodity;
use App\Entity\HesabdariDoc;
use App\Entity\HesabdariRow;
use App\Entity\HesabdariTable;
use App\Entity\Log as EntityLog;
use App\Entity\Money;
use App\Entity\PayInfoTemp;
use App\Entity\Person;
use App\Entity\PlugGhestaDoc;
use App\Entity\PlugGhestaItem;
use App\Entity\PlugNoghreOrder;
use App\Entity\Salary;
use App\Entity\StoreroomTicket;
use App\Service\Access;
use App\Service\AccountingPermissionService;
use App\Service\Explore;
use App\Service\Extractor;
use App\Service\Jdate;
use App\Service\JsonResp;
use App\Service\Log;
use App\Service\Provider;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use App\Repository\HesabdariTableRepository;

class HesabdariController extends AbstractController
{
    private array $tableExport = [];
    #[Route('/api/accounting/doc/get', name: 'app_accounting_doc_get')]
    public function app_accounting_doc_get(Jdate $jdate, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (!array_key_exists('code', $params))
            $this->createNotFoundException();


        $acc = $access->hasRole('accounting');
        if (!$acc)
            throw $this->createAccessDeniedException();
        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'bid' => $acc['bid'],
            'year' => $acc['year'],
            'code' => $params['code'],
            'money' => $acc['money']
        ]);
        if (!$doc)
            throw $this->createNotFoundException();
        //add shortlink to doc
        if (!$doc->getShortlink()) {
            $doc->setShortlink($provider->RandomString(8));
            $entityManager->persist($doc);
            $entityManager->flush();
        }
        $rows = [];
        $rowsObj = $entityManager->getRepository(HesabdariRow::class)->findBy(
            ['doc' => $doc]
        );
        foreach ($rowsObj as $item) {
            $temp = [];
            $temp['id'] = $item->getId();
            $temp['bs'] = $item->getBs();
            $temp['bd'] = $item->getBd();
            $temp['des'] = $item->getDes();
            $temp['table'] = $item->getRef()->getName();
            $temp['tableCode'] = $item->getRef()->getCode();
            $temp['referral'] = $item->getReferral();
            if ($item->getPerson()) {
                $temp['typeLabel'] = 'شخص';
                $temp['type'] = 'person';
                $temp['ref'] = $item->getPerson()->getNikeName();
                $temp['refCode'] = $item->getPerson()->getCode();
                $temp['person'] = [
                    'id' => $item->getPerson()->getId(),
                    'code' => $item->getPerson()->getCode(),
                    'nikename' => $item->getPerson()->getNikename(),
                    'name' => $item->getPerson()->getName(),
                    'tel' => $item->getPerson()->getTel(),
                    'mobile' => $item->getPerson()->getMobile(),
                    'address' => $item->getPerson()->getAddress(),
                    'des' => $item->getPerson()->getDes(),
                    'shomaresabt' => $item->getperson()->getSabt(),
                    'codeeghtesadi' => $item->getPerson()->getCodeeghtesadi(),
                    'postalcode' => $item->getPerson()->getPostalCode()
                ];
            } elseif ($item->getBank()) {
                $temp['typeLabel'] = 'حسابهای بانکی';
                $temp['type'] = 'bank';
                $temp['ref'] = $item->getBank()->getName();
                $temp['refCode'] = $item->getBank()->getCode();
                $temp['bank'] = [
                    'id' => $item->getBank()->getId(),
                    'name' => $item->getBank()->getName(),
                    'cardNum' => $item->getBank()->getCardNum(),
                    'shaba' => $item->getBank()->getShaba(),
                    'accountNum' => $item->getBank()->getAccountNum(),
                    'owner' => $item->getBank()->getOwner(),
                    'shobe' => $item->getBank()->getShobe(),
                    'posNum' => $item->getBank()->getPosNum(),
                    'des' => $item->getBank()->getDes(),
                    'mobileInternetBank' => $item->getBank()->getMobileInternetBank(),
                    'code' => $item->getBank()->getCode(),
                ];
            } elseif ($item->getCommodity()) {
                $temp['typeLabel'] = 'موجودی کالا';
                $temp['type'] = 'commodity';
                $temp['ref'] = $item->getCommodity()->getName();
                $temp['refCode'] = $item->getCommodity()->getCode();
                $temp['count'] = $item->getCommdityCount();
                if ($doc->getType() == 'sell')
                    $temp['unitPrice'] = $item->getBs() / $item->getCommdityCount();
                elseif ($doc->getType() == 'buy')
                    $temp['unitPrice'] = $item->getBd() / $item->getCommdityCount();
                $temp['commodity'] = [
                    'id' => $item->getCommodity()->getId(),
                    'name' => $item->getCommodity()->getName(),
                    'des' => $item->getCommodity()->getDes(),
                    'code' => $item->getCommodity()->getCode(),
                    'unit' => $item->getCommodity()->getUnit()->getName(),
                ];
            } elseif ($item->getSalary()) {
                $temp['typeLabel'] = 'تنخواه گردان';
                $temp['type'] = 'salary';
                $temp['ref'] = $item->getSalary()->getName();
                $temp['refCode'] = $item->getSalary()->getCode();
                $temp['salary'] = [
                    'id' => $item->getSalary()->getId(),
                    'name' => $item->getSalary()->getName(),
                    'des' => $item->getSalary()->getDes(),
                    'code' => $item->getSalary()->getCode(),
                ];
            } elseif ($item->getCashdesk()) {
                $temp['typeLabel'] = 'صندوق';
                $temp['type'] = 'cashdesk';
                $temp['ref'] = $item->getCashdesk()->getName();
                $temp['refCode'] = $item->getCashdesk()->getCode();
                $temp['cashdesk'] = [
                    'id' => $item->getCashdesk()->getId(),
                    'name' => $item->getCashdesk()->getName(),
                    'des' => $item->getCashdesk()->getDes(),
                    'code' => $item->getCashdesk()->getCode(),
                ];
            } else {
                $temp['typeLabel'] = $item->getRef()->getName();
                $temp['type'] = 'calc';
                $temp['ref'] = $item->getRef()->getName();
                $temp['refCode'] = $item->getRef()->getCode();
            }
            $rows[] = $temp;
        }
        //get related docs
        $rds = [];
        foreach ($doc->getRelatedDocs() as $relatedDoc) {
            $temp = [];
            $temp['amount'] = $relatedDoc->getAmount();
            $temp['des'] = $relatedDoc->getDes();
            $temp['date'] = $relatedDoc->getDate();
            $temp['type'] = $relatedDoc->getType();
            $temp['code'] = $relatedDoc->getCode();
            $rds[] = $temp;
        }
        return $this->json([
            'doc' => JsonResp::SerializeHesabdariDoc($doc),
            'rows' => $rows,
            'relatedDocs' => $rds
        ]);
    }

    #[Route('/api/accounting/search', name: 'app_hesabdari_search', methods: ['POST'])]
    public function search(
        Request $request,
        Access $access,
        EntityManagerInterface $entityManager,
        HesabdariTableRepository $hesabdariTableRepository,
        Jdate $jdate
    ): JsonResponse {
        $acc = $access->hasRole('acc');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $params = json_decode($request->getContent(), true) ?? [];

        // Input parameters
        $filters = $params['filters'] ?? [];
        $pagination = $params['pagination'] ?? ['page' => 1, 'limit' => 10];
        $sort = $params['sort'] ?? ['sortBy' => 'id', 'sortDesc' => true];
        $type = $params['type'] ?? 'all';

        // Set pagination parameters
        $page = max(1, $pagination['page'] ?? 1);
        $limit = max(1, min(100, $pagination['limit'] ?? 10));

        // Build base query
        $queryBuilder = $entityManager->createQueryBuilder()
            ->select('DISTINCT d.id, d.dateSubmit, d.date, d.type, d.code, d.des, d.amount')
            ->addSelect('u.fullName as submitter')
            ->from('App\Entity\HesabdariDoc', 'd')
            ->leftJoin('d.submitter', 'u')
            ->leftJoin('d.hesabdariRows', 'r')
            ->leftJoin('r.ref', 't')
            ->where('d.bid = :bid')
            ->andWhere('d.year = :year')
            ->andWhere('d.money = :money')
            ->setParameter('bid', $acc['bid'])
            ->setParameter('year', $acc['year'])
            ->setParameter('money', $acc['money']);

        // Add type filter if not 'all'
        if ($type !== 'all') {
            $queryBuilder->andWhere('d.type = :type')
                ->setParameter('type', $type);
        }

        // Apply filters
        if (!empty($filters)) {
            // Text search
            if (isset($filters['search'])) {
                $searchValue = is_array($filters['search']) ? $filters['search']['value'] : $filters['search'];
                $queryBuilder->leftJoin('r.person', 'p')
                    ->andWhere(
                        $queryBuilder->expr()->orX(
                            'd.code LIKE :search',
                            'd.des LIKE :search',
                            'd.date LIKE :search',
                            'd.amount LIKE :search',
                            'p.nikename LIKE :search',
                            't.name LIKE :search',
                            't.code LIKE :search'
                        )
                    )
                    ->setParameter('search', "%{$searchValue}%");
            }

            // Account filter
            if (isset($filters['account'])) {
                $accountCodes = $hesabdariTableRepository->findAllSubAccountCodes($filters['account'], $acc['bid']->getId());
                if (!empty($accountCodes)) {
                    $queryBuilder->andWhere('t.code IN (:accountCodes)')
                        ->setParameter('accountCodes', $accountCodes);
                } else {
                    $queryBuilder->andWhere('1 = 0');
                }
            }

            // Time filter
            if (isset($filters['timeFilter'])) {
                $today = $jdate->jdate('Y/m/d', time());
                switch ($filters['timeFilter']) {
                    case 'today':
                        $queryBuilder->andWhere('d.date = :today')
                            ->setParameter('today', $today);
                        break;
                    case 'week':
                        $weekStart = $jdate->jdate('Y/m/d', strtotime('-6 days'));
                        $queryBuilder->andWhere('d.date BETWEEN :weekStart AND :today')
                            ->setParameter('weekStart', $weekStart)
                            ->setParameter('today', $today);
                        break;
                    case 'month':
                        $monthStart = $jdate->jdate('Y/m/01', time());
                        $queryBuilder->andWhere('d.date BETWEEN :monthStart AND :today')
                            ->setParameter('monthStart', $monthStart)
                            ->setParameter('today', $today);
                        break;
                    case 'custom':
                        if (isset($filters['date']) && isset($filters['date']['from']) && isset($filters['date']['to'])) {
                            // تبدیل تاریخ‌های شمسی به میلادی
                            $fromDate = $filters['date']['from'];
                            $toDate = $filters['date']['to'];
                            
                            // اطمینان از فرمت صحیح تاریخ‌ها
                            if (strpos($fromDate, '/') !== false && strpos($toDate, '/') !== false) {
                                $queryBuilder->andWhere('d.date BETWEEN :dateFrom AND :dateTo')
                                    ->setParameter('dateFrom', $fromDate)
                                    ->setParameter('dateTo', $toDate);
                            }
                        }
                        break;
                }
            }
        }

        // Apply sorting
        $sortField = is_array($sort['sortBy']) ? ($sort['sortBy']['key'] ?? 'id') : ($sort['sortBy'] ?? 'id');
        $sortDirection = ($sort['sortDesc'] ?? true) ? 'DESC' : 'ASC';
        $queryBuilder->orderBy("d.$sortField", $sortDirection);

        // Calculate total items
        $totalItemsQuery = clone $queryBuilder;
        $totalItems = $totalItemsQuery->select('COUNT(DISTINCT d.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Apply pagination
        $queryBuilder->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);

        $docs = $queryBuilder->getQuery()->getArrayResult();

        $dataTemp = [];
        foreach ($docs as $doc) {
            $item = [
                'id' => $doc['id'],
                'dateSubmit' => $doc['dateSubmit'],
                'date' => $doc['date'],
                'type' => $doc['type'],
                'code' => $doc['code'],
                'des' => $doc['des'],
                'amount' => $doc['amount'],
                'submitter' => $doc['submitter'],
            ];

            // Get related person info if applicable
            if (in_array($doc['type'], ['rfsell', 'rfbuy', 'buy', 'sell'])) {
                $personInfo = $entityManager->createQueryBuilder()
                    ->select('p.id, p.nikename, p.code')
                    ->from('App\Entity\HesabdariRow', 'r')
                    ->join('r.person', 'p')
                    ->where('r.doc = :docId')
                    ->andWhere('r.person IS NOT NULL')
                    ->setParameter('docId', $doc['id'])
                    ->setMaxResults(1)
                    ->getQuery()
                    ->getOneOrNullResult();

                $item['person'] = $personInfo ? [
                    'id' => $personInfo['id'],
                    'nikename' => $personInfo['nikename'],
                    'code' => $personInfo['code'],
                ] : null;
            }

            // Get payment status
            $pays = $entityManager->createQueryBuilder()
                ->select('SUM(rd.amount) as total_pays')
                ->from('App\Entity\HesabdariDoc', 'd')
                ->leftJoin('d.relatedDocs', 'rd')
                ->where('d.id = :docId')
                ->setParameter('docId', $doc['id'])
                ->getQuery()
                ->getSingleScalarResult();

            $item['status'] = ($pays && $pays >= $doc['amount']) ? 'تسویه شده' : 'تسویه نشده';

            $dataTemp[] = $item;
        }

        return $this->json([
            'items' => $dataTemp,
            'total' => (int) $totalItems,
            'page' => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * @throws \ReflectionException
     */
    #[Route('/api/accounting/insert', name: 'app_accounting_insert')]
    public function app_accounting_insert(AccountingPermissionService $accountingPermissionService, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager, Jdate $jdate): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (!array_key_exists('type', $params))
            $this->createNotFoundException();

        $roll = '';
        if ($params['type'] == 'person_receive' || $params['type'] == 'person_send')
            $roll = 'person';
        elseif ($params['type'] == 'sell_receive')
            $roll = 'sell';
        elseif ($params['type'] == 'buy_send')
            $roll = 'buy';
        elseif ($params['type'] == 'transfer')
            $roll = 'bankTransfer';
        else
            $roll = $params['type'];

        $acc = $access->hasRole($roll);
        if (!$acc)
            throw $this->createAccessDeniedException();

        $pkgcntr = $accountingPermissionService->canRegisterAccountingDoc($acc['bid']);
        if ($pkgcntr['code'] == 4) {
            return $this->json([
                'result' => 4,
                'msg' => $pkgcntr['message']
            ]);
        }
        if (!array_key_exists('rows', $params) || count($params['rows']) < 2)
            throw $this->createNotFoundException('rows is to short');
        if (!array_key_exists('date', $params) || !array_key_exists('des', $params))
            throw $this->createNotFoundException('some params mistake');
        if (array_key_exists('update', $params) && $params['update'] != '') {
            $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                'bid' => $acc['bid'],
                'year' => $acc['year'],
                'code' => $params['update'],
                'money' => $acc['money']
            ]);
            if (!$doc)
                throw $this->createNotFoundException('document not found.');
            $doc->setDes($params['des']);
            $doc->setDate($params['date']);
            $doc->setMoney($acc['money']);
            if (array_key_exists('refData', $params))
                $doc->setRefData($params['refData']);
            if (array_key_exists('plugin', $params))
                $doc->setPlugin($params['plugin']);

            $entityManager->persist($doc);
            $entityManager->flush();
            $rows = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'doc' => $doc
            ]);
            foreach ($rows as $row)
                $entityManager->remove($row);
        } else {
            $doc = new HesabdariDoc();
            $doc->setBid($acc['bid']);
            $doc->setYear($acc['year']);
            $doc->setDes($params['des']);
            $doc->setDateSubmit(time());
            $doc->setType($params['type']);
            $doc->setDate($params['date']);
            $doc->setSubmitter($this->getUser());
            $doc->setMoney($acc['money']);
            $doc->setCode($provider->getAccountingCode($acc['bid'], 'accounting'));
            if (array_key_exists('refData', $params))
                $doc->setRefData($params['refData']);
            if (array_key_exists('plugin', $params))
                $doc->setPlugin($params['plugin']);
            $entityManager->persist($doc);
            $entityManager->flush();
        }

        //add document to related docs
        if (array_key_exists('related', $params)) {
            $relatedDoc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                'code' => $params['related'],
                'bid' => $doc->getBid(),
                'money' => $acc['money']
            ]);
            if ($relatedDoc) {
                $relatedDoc->addRelatedDoc($doc);
                $entityManager->persist($relatedDoc);
                $entityManager->flush();
            }
        }

        $amount = 0;
        foreach ($params['rows'] as $row) {
            $row['bs'] = str_replace(',', '', $row['bs']);
            $row['bd'] = str_replace(',', '', $row['bd']);

            $hesabdariRow = new HesabdariRow();
            $hesabdariRow->setBid($acc['bid']);
            $hesabdariRow->setYear($acc['year']);
            $hesabdariRow->setDoc($doc);
            $hesabdariRow->setBs($row['bs']);
            $hesabdariRow->setBd($row['bd']);
            $ref = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
                'code' => $row['table']
            ]);
            $hesabdariRow->setRef($ref);

            $entityManager->persist($hesabdariRow);

            if (array_key_exists('referral', $row))
                $hesabdariRow->setReferral($row['referral']);
            $amount += $row['bs'];
            //check is type is person
            if ($row['type'] == 'person') {
                $person = $entityManager->getRepository(Person::class)->find($row['id']);
                if (!$person)
                    throw $this->createNotFoundException('person not found');
                elseif ($person->getBid()->getId() != $acc['bid']->getId())
                    throw $this->createAccessDeniedException('person is not in this business');
                $hesabdariRow->setPerson($person);
            } elseif ($row['type'] == 'cheque') {
                $person = $entityManager->getRepository(Person::class)->findOneBy([
                    'bid' => $acc['bid'],
                    'id' => $row['chequeOwner']
                ]);
                $cheque = new Cheque();
                $cheque->setBid($acc['bid']);
                $cheque->setSubmitter($this->getUser());
                $cheque->setPayDate($row['chequeDate']);
                $cheque->setBankOncheque($row['chequeBank']);
                $cheque->setRef($hesabdariRow->getRef());
                $cheque->setNumber($row['chequeNum']);
                $cheque->setSayadNum($row['chequeSayadNum']);
                $cheque->setDateSubmit(time());
                $cheque->setDes($row['des']);
                $dateArray = explode('/', $row['chequeDate']);
                $dateGre = strtotime($jdate->jalali_to_gregorian($dateArray['0'], $dateArray['1'], $dateArray['2'], '/'));
                $cheque->setDateStamp($dateGre);
                $cheque->setPerson($person);
                $cheque->setRef($entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => $row['table']]));
                $cheque->setType($row['chequeType']);
                if ($cheque->getType() == 'input')
                    $cheque->setAmount($hesabdariRow->getBd());
                else
                    $cheque->setAmount($hesabdariRow->getBs());
                $cheque->setLocked(false);
                $cheque->setRejected(false);
                $cheque->setStatus('پاس نشده');
                $entityManager->persist($cheque);
                $entityManager->flush();
                $hesabdariRow->setCheque($cheque);
            } elseif ($row['type'] == 'bank') {
                $bank = $entityManager->getRepository(BankAccount::class)->findOneBy([
                    'id' => $row['id'],
                    'bid' => $acc['bid']
                ]);
                if (!$bank)
                    throw $this->createNotFoundException('bank not found');
                $hesabdariRow->setBank($bank);
            } elseif ($row['type'] == 'salary') {
                $salary = $entityManager->getRepository(Salary::class)->find($row['id']);
                if (!$salary)
                    throw $this->createNotFoundException('salary not found');
                elseif ($salary->getBid()->getId() != $acc['bid']->getId())
                    throw $this->createAccessDeniedException('bank is not in this business');
                $hesabdariRow->setSalary($salary);
            } elseif ($row['type'] == 'cashdesk') {
                $cashdesk = $entityManager->getRepository(Cashdesk::class)->find($row['id']);
                if (!$cashdesk)
                    throw $this->createNotFoundException('cashdesk not found');
                elseif ($cashdesk->getBid()->getId() != $acc['bid']->getId())
                    throw $this->createAccessDeniedException('bank is not in this business');
                $hesabdariRow->setCashdesk($cashdesk);
            } elseif ($row['type'] == 'commodity') {
                $row['count'] = str_replace(',', '', $row['count']);
                $commodity = $entityManager->getRepository(Commodity::class)->find($row['commodity']['id']);
                if (!$commodity)
                    throw $this->createNotFoundException('commodity not found');
                elseif ($commodity->getBid()->getId() != $acc['bid']->getId())
                    throw $this->createAccessDeniedException('$commodity is not in this business');
                $hesabdariRow->setCommodity($commodity);
                $hesabdariRow->setCommdityCount($row['count']);
            }

            if (array_key_exists('plugin', $row))
                $hesabdariRow->setPlugin($row['plugin']);
            if (array_key_exists('refData', $row))
                $hesabdariRow->setRefData($row['refData']);


            $hesabdariRow->setDes($row['des']);
            $entityManager->persist($hesabdariRow);
            $entityManager->flush();
        }
        $doc->setAmount($amount);
        $entityManager->persist($doc);

        //check ghesta
        if (array_key_exists('ghestaId', $params)) {
            $ghesta = $entityManager->getRepository(PlugGhestaDoc::class)->find($params['ghestaId']);
            if ($ghesta) {
                $ghestaItem = $entityManager->getRepository(PlugGhestaItem::class)->findOneBy([
                    'doc' => $ghesta,
                    'num' => $params['ghestaNum']
                ]);
                if ($ghestaItem) {
                    $ghestaItem->setHesabdariDoc($doc);
                    $entityManager->persist($ghestaItem);
                }
            }
        }
        $entityManager->flush();
        $log->insert(
            'حسابداری',
            'سند حسابداری شماره ' . $doc->getCode() . ' ثبت / ویرایش شد.',
            $this->getUser(),
            $request->headers->get('activeBid'),
            $doc
        );

        return $this->json([
            'result' => 1,
            'doc' => $provider->Entity2Array($doc, 0)
        ]);
    }

    #[Route('/api/accounting/remove', name: 'app_accounting_remove_doc')]
    public function app_accounting_remove_doc(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (!array_key_exists('code', $params))
            $this->createNotFoundException();
        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
            'code' => $params['code'],
            'bid' => $request->headers->get('activeBid')
        ]);
        if (!$doc)
            throw $this->createNotFoundException();
        $roll = '';
        if ($doc->getType() == 'person_receive' || $doc->getType() == 'person_send')
            $roll = 'person';
        elseif ($doc->getType() == 'sell_receive')
            $roll = 'sell';
        elseif ($doc->getType() == 'buy_send')
            $roll = 'buy';
        elseif ($doc->getType() == 'transfer')
            $roll = 'bankTransfer';
        else
            $roll = $doc->getType();
        $acc = $access->hasRole($roll);
        if (!$acc)
            throw $this->createAccessDeniedException();
        $rows = $entityManager->getRepository(HesabdariRow::class)->findBy([
            'doc' => $doc
        ]);
        if ($doc->getPlugin() == 'plugNoghreOrder') {
            $order = $entityManager->getRepository(PlugNoghreOrder::class)->findOneBy([
                'doc' => $doc
            ]);
            if ($order)
                $entityManager->remove($order);
        }
        //check wallet online transactions
        $tempPays = $entityManager->getRepository(PayInfoTemp::class)->findOneBy(['doc' => $doc]);
        if ($tempPays) {
            //doc has transaction
            return $this->json([
                'result' => 2,
                'message' => 'سند به دلیل داشتن تراکنش پرداخت آنلاین قابل حذف نیست.'
            ]);
        }
        //check storeroom tickets
        $tickets = $entityManager->getRepository(StoreroomTicket::class)->findBy(['doc' => $doc]);
        foreach ($tickets as $ticket)
            $entityManager->remove($ticket);
        //remove rows and check sub systems
        foreach ($rows as $row) {
            if ($row->getCheque()) {
                if ($row->getCheque()->isLocked()) {
                    //doc has transaction
                    return $this->json([
                        'result' => 2,
                        'message' => 'سند به دلیل داشتن تراکنش مرتبط با چک بانکی قابل حذف نیست.'
                    ]);
                }
                $log->insert('بانکداری', 'چک  شماره  شماره ' . $row->getCheque()->getNumber() . ' حذف شد.', $this->getUser(), $request->headers->get('activeBid'));
                $entityManager->remove($row->getCheque());
            }
            $entityManager->remove($row);
        }

        //check ghesta items
        $ghestaItems = $entityManager->getRepository(PlugGhestaItem::class)->findBy(['hesabdariDoc' => $doc]);
        foreach ($ghestaItems as $ghestaItem) {
            $ghestaItem->setHesabdariDoc(null);
            $entityManager->persist($ghestaItem);
        }
        $entityManager->flush();

        //check ghesta doc
        $ghesta = $entityManager->getRepository(PlugGhestaDoc::class)->findOneBy(['mainDoc' => $doc]);
        if ($ghesta) {
            $entityManager->remove($ghesta);
            $entityManager->flush();
        }

        //check related docs
        foreach ($doc->getRelatedDocs() as $relatedDoc) {
            if ($relatedDoc->getType() != 'walletPay') {
                //check ghesta items for related docs
                $relatedGhestaItems = $entityManager->getRepository(PlugGhestaItem::class)->findBy(['hesabdariDoc' => $relatedDoc]);
                foreach ($relatedGhestaItems as $ghestaItem) {
                    $ghestaItem->setHesabdariDoc(null);
                    $entityManager->persist($ghestaItem);
                }
                $entityManager->flush();

                //check ghesta doc for related docs
                $relatedGhesta = $entityManager->getRepository(PlugGhestaDoc::class)->findOneBy(['mainDoc' => $relatedDoc]);
                if ($relatedGhesta) {
                    $entityManager->remove($relatedGhesta);
                    $entityManager->flush();
                }

                $items = $entityManager->getRepository(HesabdariRow::class)->findBy(['doc' => $relatedDoc]);
                foreach ($items as $item)
                    $entityManager->remove($item);
                $entityManager->remove($relatedDoc);
                $logs = $entityManager->getRepository(EntityLog::class)->findBy(['doc' => $relatedDoc]);
                foreach ($logs as $item) {
                    $item->setDoc(null);
                    $entityManager->persist($item);
                }
                $entityManager->flush();
                $code = $doc->getCode();
                $log->insert('حسابداری', 'سند حسابداری شماره ' . $code . ' حذف شد.', $this->getUser(), $request->headers->get('activeBid'));
            }
        }

        //delete logs from documents
        $logs = $entityManager->getRepository(EntityLog::class)->findBy(['doc' => $doc]);
        foreach ($logs as $item) {
            $item->setDoc(null);
            $entityManager->persist($item);
        }
        $entityManager->flush();

        $code = $doc->getCode();
        foreach ($doc->getNotes() as $note) {
            $entityManager->remove($note);
        }
        $entityManager->flush();

        $entityManager->remove($doc);
        $entityManager->flush();
        $log->insert('حسابداری', 'سند حسابداری شماره ' . $code . ' حذف شد.', $this->getUser(), $request->headers->get('activeBid'));
        return $this->json(['result' => 1]);
    }

    #[Route('/api/accounting/remove/group', name: 'app_accounting_remove_doc_group')]
    public function app_accounting_remove_doc_group(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (!array_key_exists('items', $params))
            $this->createNotFoundException();
        foreach ($params['items'] as $item) {
            $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy([
                'code' => $item['code'],
                'bid' => $request->headers->get('activeBid')
            ]);
            if (!$doc)
                throw $this->createNotFoundException();
            $roll = '';
            if ($doc->getType() == 'person_receive' || $doc->getType() == 'person_send')
                $roll = 'person';
            elseif ($doc->getType() == 'sell_receive')
                $roll = 'sell';
            elseif ($doc->getType() == 'buy_send')
                $roll = 'buy';
            elseif ($params['type'] == 'transfer')
                $roll = 'bankTransfer';
            else
                $roll = $doc->getType();
            $acc = $access->hasRole($roll);
            if (!$acc)
                throw $this->createAccessDeniedException();
            $rows = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'doc' => $doc
            ]);
            if ($doc->getPlugin() == 'plugNoghreOrder') {
                $order = $entityManager->getRepository(PlugNoghreOrder::class)->findOneBy([
                    'doc' => $doc
                ]);
                if ($order)
                    $entityManager->remove($order);
            }
            //check wallet online transactions
            $tempPays = $entityManager->getRepository(PayInfoTemp::class)->findOneBy(['doc' => $doc]);
            if ($tempPays) {
                //doc has transaction
                return $this->json([
                    'result' => 2,
                    'message' => 'سند به دلیل داشتن تراکنش پرداخت آنلاین قابل حذف نیست.'
                ]);
            }
            //check storeroom tickets
            $tickets = $entityManager->getRepository(StoreroomTicket::class)->findBy(['doc' => $doc]);
            foreach ($tickets as $ticket)
                $entityManager->remove($ticket);
            //remove rows and check sub systems
            foreach ($rows as $row) {
                if ($row->getCheque()) {
                    if ($row->getCheque()->isLocked()) {
                        //doc has transaction
                        return $this->json([
                            'result' => 2,
                            'message' => 'سند به دلیل داشتن تراکنش مرتبط با چک بانکی قابل حذف نیست.'
                        ]);
                    }
                    $log->insert('بانکداری', 'چک  شماره  شماره ' . $row->getCheque()->getNumber() . ' حذف شد.', $this->getUser(), $request->headers->get('activeBid'));
                    $entityManager->remove($row->getCheque());
                }
                $entityManager->remove($row);
            }

            foreach ($doc->getRelatedDocs() as $relatedDoc) {
                if ($relatedDoc->getType() != 'walletPay') {
                    $items = $entityManager->getRepository(HesabdariRow::class)->findBy(['doc' => $relatedDoc]);
                    foreach ($items as $item)
                        $entityManager->remove($item);
                    $entityManager->remove($relatedDoc);
                    $logs = $entityManager->getRepository(EntityLog::class)->findBy(['doc' => $relatedDoc]);
                    foreach ($logs as $item) {
                        $item->setDoc(null);
                        $entityManager->persist($item);
                    }
                    $entityManager->flush();
                    $code = $doc->getCode();
                    $entityManager->remove($relatedDoc);
                    $log->insert('حسابداری', 'سند حسابداری شماره ' . $code . ' حذف شد.', $this->getUser(), $request->headers->get('activeBid'));
                }
            }

            //delete logs from documents
            $logs = $entityManager->getRepository(EntityLog::class)->findBy(['doc' => $doc]);
            foreach ($logs as $item) {
                $item->setDoc(null);
                $entityManager->persist($item);
            }
            $code = $doc->getCode();
            foreach ($doc->getNotes() as $note) {
                $entityManager->remove($note);
            }
            $entityManager->remove($doc);
            $entityManager->flush();
            $log->insert('حسابداری', 'سند حسابداری شماره ' . $code . ' حذف شد.', $this->getUser(), $request->headers->get('activeBid'));

        }
        return $this->json(['result' => 1]);
    }

    #[Route('/api/accounting/rows/search', name: 'app_accounting_rows_search')]
    public function app_accounting_rows_search(Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $params = [];
        if ($content = $request->getContent()) {
            $params = json_decode($content, true);
        }
        if (!array_key_exists('type', $params))
            $this->createNotFoundException();
        $roll = '';
        if ($params['type'] == 'person')
            $roll = 'person';
        if ($params['type'] == 'person_receive' || $params['type'] == 'person_send')
            $roll = 'person';
        elseif ($params['type'] == 'sell_receive')
            $roll = 'sell';
        elseif ($params['type'] == 'bank')
            $roll = 'banks';
        elseif ($params['type'] == 'buy_send')
            $roll = 'buy';
        elseif ($params['type'] == 'transfer')
            $roll = 'bankTransfer';
        elseif ($params['type'] == 'all')
            $roll = 'accounting';
        else
            $roll = $params['type'];

        $acc = $access->hasRole($roll);
        if (!$acc)
            throw $this->createAccessDeniedException();
        if ($params['type'] == 'person') {
            $person = $entityManager->getRepository(Person::class)->findOneBy([
                'bid' => $acc['bid'],
                'code' => $params['id'],
            ]);
            if (!$person)
                throw $this->createNotFoundException();

            $data = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'person' => $person,
            ], [
                'id' => 'DESC'
            ]);
        } elseif ($params['type'] == 'bank') {
            $bank = $entityManager->getRepository(BankAccount::class)->findOneBy([
                'bid' => $acc['bid'],
                'code' => $params['id'],
            ]);
            if (!$bank)
                throw $this->createNotFoundException();

            $data = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'bank' => $bank,
            ], [
                'id' => 'DESC'
            ]);
        } elseif ($params['type'] == 'cashdesk') {
            $cashdesk = $entityManager->getRepository(Cashdesk::class)->findOneBy([
                'bid' => $acc['bid'],
                'code' => $params['id'],
            ]);
            if (!$cashdesk)
                throw $this->createNotFoundException();

            $data = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'cashdesk' => $cashdesk,
            ], [
                'id' => 'DESC'
            ]);
        } elseif ($params['type'] == 'salary') {
            $salary = $entityManager->getRepository(Salary::class)->findOneBy([
                'bid' => $acc['bid'],
                'code' => $params['id'],
            ]);
            if (!$salary)
                throw $this->createNotFoundException();

            $data = $entityManager->getRepository(HesabdariRow::class)->findBy([
                'salary' => $salary,
            ], [
                'id' => 'DESC'
            ]);
        }
        $dataTemp = [];
        foreach ($data as $item) {
            $temp = [
                'id' => $item->getId(),
                'dateSubmit' => $item->getDoc()->getDateSubmit(),
                'date' => $item->getDoc()->getDate(),
                'type' => $item->getDoc()->getType(),
                'ref' => $item->getRef()->getName(),
                'des' => $item->getDes(),
                'bs' => $item->getBs(),
                'bd' => $item->getBd(),
                'code' => $item->getDoc()->getCode(),
                'submitter' => $item->getDoc()->getSubmitter()->getFullName()
            ];
            $dataTemp[] = $temp;
        }
        return $this->json($dataTemp);
    }

    #[Route('/api/accounting/table/get', name: 'app_accounting_table_get')]
    public function app_accounting_table_get(Jdate $jdate, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $business = $acc['bid']; // شیء Business از Access
        $temp = [];

        // دریافت تمام نودها
        $nodes = $entityManager->getRepository(HesabdariTable::class)->findAll();

        foreach ($nodes as $node) {
            $nodeBid = $node->getBid(); // شیء Business یا null

            // فقط نودهایی که عمومی هستند (bid=null) یا متعلق به کسب‌وکار فعلی‌اند
            if ($nodeBid === null || ($nodeBid && $nodeBid->getId() === $business->getId())) {
                if ($this->hasChild($entityManager, $node)) {
                    $temp[$node->getCode()] = [
                        'text' => $node->getName(),
                        'id' => $node->getCode() ?? $node->getId(),
                        'children' => $this->getFilteredChildsLabel($entityManager, $node, $business),
                    ];
                } else {
                    $temp[$node->getCode()] = [
                        'text' => $node->getName(),
                        'id' => $node->getCode() ?? $node->getId(),
                    ];
                }
                $temp[$node->getCode()]['is_public'] = $nodeBid === null;
            }
        }

        return $this->json($temp);
    }

    // متد جدید برای دریافت کدهای زیرمجموعه‌ها با فیلتر بر اساس bid
    private function getFilteredChildsLabel(EntityManagerInterface $entityManager, HesabdariTable $node, Business $business): array
    {
        $childs = $entityManager->getRepository(HesabdariTable::class)->findBy([
            'upper' => $node
        ]);
        $temp = [];
        foreach ($childs as $child) {
            $childBid = $child->getBid(); // شیء Business یا null

            // فقط نودهایی که عمومی هستند (bid=null) یا متعلق به کسب‌وکار فعلی‌اند
            if ($childBid === null || ($childBid && $childBid->getId() === $business->getId())) {
                $temp[] = $child->getCode();
            }
        }
        return $temp;
    }

    #[Route('/api/accounting/table/childs/{type}', name: 'app_accounting_table_childs')]
    public function app_accounting_table_childs(string $type, Jdate $jdate, Provider $provider, Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole($type);
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $business = $acc['bid']; // شیء Business از Access

        if ($type === 'cost') {
            $cost = $entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => 67]);
            if (!$cost) {
                return $this->json(['result' => 0, 'message' => 'ردیف حساب هزینه پیدا نشد'], 404);
            }
            return $this->json($this->getFilteredChilds($entityManager, $cost, $business));
        } elseif ($type === 'income') {
            $income = $entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => 56]);
            if (!$income) {
                return $this->json(['result' => 0, 'message' => 'ردیف حساب درآمد پیدا نشد'], 404);
            }
            return $this->json($this->getFilteredChilds($entityManager, $income, $business));
        }

        return $this->json([]);
    }

    // متد اصلاح‌شده برای فیلتر کردن زیرمجموعه‌ها بر اساس bid
    private function getFilteredChilds(EntityManagerInterface $entityManager, HesabdariTable $node, Business $business): array
    {
        $childs = $entityManager->getRepository(HesabdariTable::class)->findBy([
            'upper' => $node
        ]);
        $temp = [];
        foreach ($childs as $child) {
            $childBid = $child->getBid(); // شیء Business یا null

            // فقط نودهایی که عمومی هستند (bid=null) یا متعلق به کسب‌وکار فعلی‌اند
            if ($childBid === null || ($childBid && $childBid->getId() === $business->getId())) {
                if ($child->getType() === 'calc') {
                    if ($this->hasChild($entityManager, $child)) {
                        $temp[] = [
                            'id' => $child->getCode(),
                            'label' => $child->getName(),
                            'children' => $this->getFilteredChilds($entityManager, $child, $business)
                        ];
                    } else {
                        $temp[] = [
                            'id' => $child->getCode(),
                            'label' => $child->getName(),
                        ];
                    }
                }
            }
        }
        return $temp;
    }
    private function getChildsLabel(EntityManagerInterface $entityManager, mixed $node)
    {
        $childs = $entityManager->getRepository(HesabdariTable::class)->findBy([
            'upper' => $node
        ]);
        $temp = [];
        foreach ($childs as $child) {
            $temp[] = $child->getCode();
        }
        return $temp;
    }

    private function hasChild(EntityManagerInterface $entityManager, mixed $node)
    {
        if (
            count($entityManager->getRepository(HesabdariTable::class)->findBy([
                'upper' => $node
            ])) != 0
        )
            return true;
        return false;
    }

    private function getChilds(EntityManagerInterface $entityManager, mixed $node)
    {
        $childs = $entityManager->getRepository(HesabdariTable::class)->findBy([
            'upper' => $node
        ]);
        $temp = [];
        foreach ($childs as $child) {
            if ($child->getType() == 'calc') {
                if ($this->hasChild($entityManager, $child)) {
                    $temp[] = [
                        'id' => $child->getCode(),
                        'label' => $child->getName(),
                        'children' => $this->getChilds($entityManager, $child)
                    ];
                } else {
                    $temp[] = [
                        'id' => $child->getCode(),
                        'label' => $child->getName(),
                    ];
                }
            }
        }
        return $temp;
    }

    #[Route('/api/accounting/table/add', name: 'app_accounting_table_add', methods: ['POST'])]
    public function app_accounting_table_add(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $params = json_decode($request->getContent(), true);
        if (!isset($params['text']) || !isset($params['parentId'])) {
            return $this->json(['result' => 0, 'message' => 'نام ردیف حساب و آیدی والد الزامی است'], 400);
        }

        $parentNode = $entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => $params['parentId']]);
        if (!$parentNode) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب والد پیدا نشد'], 404);
        }

        $maxAttempts = 10;
        $uniqueCode = null;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = (string) rand(1000, 999999);
            $existingNode = $entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => $code]);
            if (!$existingNode) {
                $uniqueCode = $code;
                break;
            }
        }

        if ($uniqueCode === null) {
            return $this->json(['result' => 0, 'message' => 'امکان تولید کد منحصربه‌فرد برای ردیف حساب وجود ندارد'], 500);
        }

        $newNode = new HesabdariTable();
        $newNode->setName($params['text']);
        $newNode->setCode($uniqueCode);
        $newNode->setBid($acc['bid']);
        $newNode->setUpper($parentNode);
        $newNode->setType('calc');

        $entityManager->persist($newNode);
        $entityManager->flush();

        $log->insert('حسابداری', 'ردیف حساب جدید با کد ' . $newNode->getCode() . ' اضافه شد.', $this->getUser(), $acc['bid']);

        return $this->json([
            'result' => 1,
            'node' => [
                'id' => $newNode->getCode(),
                'text' => $newNode->getName(),
                'children' => [],
                'is_public' => $newNode->getBid() ? false : true,
            ]
        ]);
    }

    #[Route('/api/accounting/table/edit', name: 'app_accounting_table_edit', methods: ['POST'])]
    public function app_accounting_table_edit(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $params = json_decode($request->getContent(), true);
        if (!isset($params['id']) || !isset($params['text'])) {
            return $this->json(['result' => 0, 'message' => 'آیدی ردیف حساب و نام جدید الزامی است'], 400);
        }

        $node = $entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => $params['id']]);
        if (!$node) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب پیدا نشد'], 404);
        }

        if (!$node->getBid()) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب عمومی قابل ویرایش نیست'], 403);
        }

        $oldName = $node->getName();
        $node->setName($params['text']);
        $entityManager->persist($node);
        $entityManager->flush();

        $log->insert('حسابداری', 'ردیف حساب با کد ' . $node->getCode() . ' از ' . $oldName . ' به ' . $params['text'] . ' ویرایش شد.', $this->getUser(), $acc['bid']);

        return $this->json([
            'result' => 1,
            'node' => [
                'id' => $node->getCode(),
                'text' => $node->getName(),
                'children' => $this->getChildsLabel($entityManager, $node),
                'is_public' => $node->getBid() ? false : true,
            ]
        ]);
    }

    #[Route('/api/accounting/table/delete', name: 'app_accounting_table_delete', methods: ['POST'])]
    public function app_accounting_table_delete(Request $request, Access $access, Log $log, EntityManagerInterface $entityManager): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $params = json_decode($request->getContent(), true);
        if (!isset($params['id'])) {
            return $this->json(['result' => 0, 'message' => 'آیدی ردیف حساب الزامی است'], 400);
        }

        $node = $entityManager->getRepository(HesabdariTable::class)->findOneBy(['code' => $params['id']]);
        if (!$node) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب پیدا نشد'], 404);
        }

        if (!$node->getBid()) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب عمومی قابل حذف نیست'], 403);
        }

        $relatedDocs = $entityManager->getRepository(HesabdariRow::class)->findBy(['ref' => $node]);
        if (count($relatedDocs) > 0) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب به دلیل داشتن سند حسابداری قابل حذف نیست'], 403);
        }

        $children = $entityManager->getRepository(HesabdariTable::class)->findBy(['upper' => $node]);
        if (count($children) > 0) {
            return $this->json(['result' => 0, 'message' => 'ردیف حساب به دلیل داشتن زیرمجموعه قابل حذف نیست'], 403);
        }

        $code = $node->getCode();
        $entityManager->remove($node);
        $entityManager->flush();

        $log->insert('حسابداری', 'ردیف حساب با کد ' . $code . ' حذف شد.', $this->getUser(), $acc['bid']);

        return $this->json(['result' => 1, 'id' => $code]);
    }
    #[Route('/api/hesabdari/print/{id}', name: 'app_hesabdari_print', methods: ['POST'])]
    public function app_hesabdari_print(Request $request, Provider $provider, Extractor $extractor, Access $access, EntityManagerInterface $entityManager, $id): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $params = $request->getPayload()->all();

        $doc = $entityManager->getRepository(HesabdariDoc::class)->findOneBy(['bid' => $acc['bid'], 'code' => $id]);
        if (!$doc) {
            return $this->json($extractor->notFound());
        }
        
        $printOptions = [
            'paper' => 'A4-L'
        ];
        if (array_key_exists('printOptions', $params)) {
            if (array_key_exists('paper', $params['printOptions'])) {
                $printOptions['paper'] = $params['printOptions']['paper'];
            }
        }

        $pdfPid = $provider->createPrint(
            $acc['bid'],
            $this->getUser(),
            $this->renderView('pdf/printers/doc.html.twig', [
                'doc' => $doc,
                'bid' => $acc['bid'],
                'page_title' => 'سند حسابداری',
                'rows' => $doc->getHesabdariRows(),
                'printOptions' => $printOptions,
                'invoiceDate' => $doc->getDate(),
            ]),
            false,
            $printOptions['paper']
        );

        return $this->json($extractor->operationSuccess($pdfPid));
        
    }

    #[Route('/api/hesabdari/tables/tree', name: 'get_hesabdari_table_tree', methods: ['GET'])]
    public function getHesabdariTableTree(Access $access, EntityManagerInterface $entityManager, Request $request): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $rootCode = $request->query->get('rootCode');
        
        if (!$rootCode) {
            return $this->json(['Success' => false, 'message' => 'کد ریشه مشخص نشده است'], 400);
        }

        // جستجوی ریشه بر اساس code
        $root = $entityManager->getRepository(HesabdariTable::class)->findOneBy([
            'code' => $rootCode,
            'bid' => [$acc['bid']->getId(), null]
        ]);

        if (!$root) {
            return $this->json(['Success' => false, 'message' => 'نود ریشه یافت نشد'], 404);
        }

        // تابع بازگشتی برای ساخت درخت
        $buildTree = function ($node) use ($entityManager, $acc, &$buildTree) {
            $children = $entityManager->getRepository(HesabdariTable::class)->findBy([
                'upper' => $node,
                'bid' => [$acc['bid']->getId(), null],
            ], ['code' => 'ASC']); // مرتب‌سازی بر اساس کد

            $result = [];
            foreach ($children as $child) {
                $childData = [
                    'code' => $child->getCode(),
                    'name' => $child->getName(),
                    'type' => $child->getType(),
                    'children' => $buildTree($child)
                ];
                $result[] = $childData;
            }

            return $result;
        };

        // ساخت درخت کامل
        $tree = [
            'code' => $root->getCode(),
            'name' => $root->getName(),
            'type' => $root->getType(),
            'children' => $buildTree($root)
        ];

        return $this->json(['Success' => true, 'data' => $tree]);
    }

    #[Route('/api/hesabdari/tables/all', name: 'get_all_hesabdari_tables', methods: ['GET'])]
    public function getAllHesabdariTables(Access $access, EntityManagerInterface $entityManager, Request $request): JsonResponse
    {
        $acc = $access->hasRole('accounting');
        if (!$acc) {
            throw $this->createAccessDeniedException();
        }

        $rootId = (int) $request->query->get('rootId', 1); // گره ریشه پیش‌فرض

        $root = $entityManager->getRepository(HesabdariTable::class)->find($rootId);
        if (!$root) {
            return $this->json(['Success' => false, 'message' => 'نود ریشه یافت نشد'], 404);
        }

        $buildTree = function ($node) use ($entityManager, $acc, &$buildTree) {
            $children = $entityManager->getRepository(HesabdariTable::class)->findBy([
                'upper' => $node,
                'bid' => [$acc['bid']->getId(), null],
            ]);

            $result = [];
            foreach ($children as $child) {
                $childData = [
                    'id' => $child->getId(),
                    'name' => $child->getName(),
                    'code' => $child->getCode(),
                    'type' => $child->getType(),
                    'children' => $buildTree($child),
                ];
                $result[] = $childData;
            }

            return $result;
        };

        $tree = [
            'id' => $root->getId(),
            'name' => $root->getName(),
            'code' => $root->getCode(),
            'type' => $root->getType(),
            'children' => $buildTree($root),
        ];

        return $this->json(['Success' => true, 'data' => $tree]);
    }
}
