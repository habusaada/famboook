"use client";

import Link from "next/link";
import { AlertCircle, Search, X } from "lucide-react";
import { Card, CardContent } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/components/ui/table";
import { RegistryPagination } from "@/components/shared/registry-pagination";
import { usePeople } from "@/lib/api/people";
import { useRegistrySearch } from "@/lib/hooks/use-registry-search";
import { ageLabel } from "@/lib/utils/date";

const genderLabel = (g: string | null) => (g === "MALE" ? "ذكر" : g === "FEMALE" ? "أنثى" : "غير محدد");

/**
 * People registry (docs/03 §93a): server-side search by Person code or
 * name, paginated, state in the URL. No National ID, contact details or
 * health data; Family context only for users who may view Families.
 */
export function PeopleRegistry() {
  const registry = useRegistrySearch();
  const { data, isLoading, isFetching, isError, error, refetch } = usePeople({ search: registry.q, page: registry.page });
  const people = data?.data ?? [];

  return (
    <div className="flex flex-col gap-5">
      <div>
        <h2 className="text-xl font-semibold tracking-tight">سجل الأشخاص</h2>
        <p className="text-sm text-muted-foreground">البحث عن الأفراد المسجلين برقم الفرد أو الاسم</p>
      </div>

      <Card size="sm">
        <CardContent className="flex flex-col gap-3 sm:flex-row sm:items-center">
          <div className="relative flex-1">
            <Search className="pointer-events-none absolute start-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
              type="search"
              value={registry.text}
              onChange={(event) => registry.setText(event.target.value)}
              placeholder="ابحث برقم الفرد أو الاسم..."
              aria-label="بحث في سجل الأشخاص"
              className="ps-8"
              data-registry-search
            />
          </div>
          {registry.text.trim() !== "" && (
            <Button variant="ghost" onClick={registry.reset} data-registry-reset>
              <X className="size-4" />
              مسح البحث
            </Button>
          )}
        </CardContent>
      </Card>

      {isError ? (
        <Alert variant="destructive">
          <AlertCircle className="size-4" />
          <AlertTitle>تعذّر تحميل سجل الأشخاص</AlertTitle>
          <AlertDescription className="flex flex-col gap-2">
            <span>{error instanceof Error ? error.message : "حدث خطأ غير متوقع أثناء الاتصال بالخادم."}</span>
            <Button variant="outline" size="sm" className="w-fit" onClick={() => refetch()}>
              إعادة المحاولة
            </Button>
          </AlertDescription>
        </Alert>
      ) : (
        <Card size="sm">
          <CardContent className="overflow-x-auto p-0" aria-busy={isFetching}>
            <Table className={isFetching && !isLoading ? "opacity-60 transition-opacity" : undefined}>
              <TableHeader>
                <TableRow>
                  <TableHead>رقم الفرد</TableHead>
                  <TableHead>الاسم</TableHead>
                  <TableHead>الجنس</TableHead>
                  <TableHead>العمر</TableHead>
                  <TableHead>الصلة بالأسرة</TableHead>
                  <TableHead>رقم الأسرة</TableHead>
                  <TableHead>الفرع</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading ? (
                  Array.from({ length: 5 }).map((_, i) => (
                    <TableRow key={i}>
                      {Array.from({ length: 7 }).map((__, j) => (
                        <TableCell key={j}>
                          <Skeleton className="h-4 w-full max-w-28" />
                        </TableCell>
                      ))}
                    </TableRow>
                  ))
                ) : people.length === 0 ? (
                  <TableRow>
                    <TableCell colSpan={7} className="py-10 text-center text-sm text-muted-foreground">
                      {registry.q ? "لا توجد نتائج مطابقة لبحثك" : "لا يوجد أشخاص مسجلون بعد"}
                    </TableCell>
                  </TableRow>
                ) : (
                  people.map((person) => (
                    <TableRow key={person.person_code} data-person={person.person_code}>
                      <TableCell className="font-medium">
                        <Link href={`/people/${person.person_code}`} className="hover:underline" dir="ltr">
                          {person.person_code}
                        </Link>
                      </TableCell>
                      <TableCell>
                        <Link href={`/people/${person.person_code}`} className="hover:underline">
                          {person.full_name}
                        </Link>
                        {person.life_status === "DECEASED" && (
                          <Badge variant="outline" className="ms-2 text-muted-foreground">
                            متوفى
                          </Badge>
                        )}
                      </TableCell>
                      <TableCell>{genderLabel(person.gender)}</TableCell>
                      <TableCell className="tabular-nums">{ageLabel(person.birth_date)}</TableCell>
                      <TableCell>
                        {person.family
                          ? person.family.is_household_head
                            ? "رب الأسرة"
                            : (person.family.relationship?.name ?? "غير محدد")
                          : "—"}
                      </TableCell>
                      <TableCell>
                        {person.family ? (
                          <Link href={`/families/${person.family.family_code}`} className="hover:underline" dir="ltr" data-family-link>
                            {person.family.family_code}
                          </Link>
                        ) : (
                          "—"
                        )}
                      </TableCell>
                      <TableCell className="text-muted-foreground">{person.family?.branch_name ?? "—"}</TableCell>
                    </TableRow>
                  ))
                )}
              </TableBody>
            </Table>
          </CardContent>
          {data && <RegistryPagination meta={data.meta} onPage={registry.setPage} unit={registry.q ? "نتيجة" : "شخص"} />}
        </Card>
      )}
    </div>
  );
}
