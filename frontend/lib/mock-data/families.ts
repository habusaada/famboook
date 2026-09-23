// Mock UI data for the Families Registry slice. Local to the frontend
// only — no backend endpoint exists yet. Field names mirror the
// canonical model in docs/02-DATA-DICTIONARY.md so this can be swapped
// for real TanStack Query + Laravel API data without changing the UI.

import type { Family } from "@/lib/types/family";

export const families: Family[] = [
  {
    familyCode: "FAM-001284",
    status: "APPROVED",
    registrationDate: "2026-08-20",
    registrationSource: "MANUAL_ENTRY",
    updatedAt: "قبل ساعتين",
    residence: {
      governorate: "عمّان",
      city: "الزرقاء",
      area: "حي النصر",
      displacementStatus: "مقيم",
    },
    members: [
      {
        personCode: "PER-004501",
        fullName: "محمد أحمد الشريف",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "MALE",
        birthDate: "1982-03-14",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004502",
        fullName: "هدى سليم عبد الرحمن",
        relationship: "SPOUSE",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "1985-07-22",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004503",
        fullName: "أحمد محمد الشريف",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2008-01-10",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004504",
        fullName: "رغد محمد الشريف",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2011-05-30",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004505",
        fullName: "يوسف محمد الشريف",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2014-09-18",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004506",
        fullName: "سلمى محمد الشريف",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2018-12-02",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001283",
    status: "PENDING_REVIEW",
    registrationDate: "2026-09-18",
    registrationSource: "PAPER_FORM",
    paperFormNo: "PF-2025-1187",
    updatedAt: "قبل 5 ساعات",
    residence: {
      governorate: "إربد",
      city: "إربد",
      area: "حي الحصن",
    },
    members: [
      {
        personCode: "PER-004512",
        fullName: "فاطمة سالم القرني",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "FEMALE",
        birthDate: "1979-02-11",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004513",
        fullName: "زيد خالد القرني",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2005-06-25",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004514",
        fullName: "مريم خالد القرني",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2009-11-08",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004515",
        fullName: "خديجة خالد القرني",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2013-04-17",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001282",
    status: "APPROVED",
    registrationDate: "2026-08-05",
    registrationSource: "VERIFIED_SOURCE",
    updatedAt: "أمس",
    residence: {
      governorate: "عمّان",
      city: "عمّان",
      area: "جبل الحسين",
    },
    members: [
      {
        personCode: "PER-004520",
        fullName: "خالد يوسف النجار",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "MALE",
        birthDate: "1975-08-30",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004521",
        fullName: "أمل حسين النجار",
        relationship: "SPOUSE",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "1978-01-05",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004522",
        fullName: "عبد الله خالد النجار",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "1999-03-21",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004523",
        fullName: "نور خالد النجار",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2002-10-14",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004524",
        fullName: "حسام خالد النجار",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2005-07-09",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004525",
        fullName: "دانة خالد النجار",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2008-02-27",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004526",
        fullName: "طارق خالد النجار",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2011-12-19",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004527",
        fullName: "سناء يوسف النجار",
        relationship: "OTHER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "1950-06-01",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001281",
    status: "NEEDS_COMPLETION",
    registrationDate: "2026-09-21",
    registrationSource: "PAPER_FORM",
    paperFormNo: "PF-2025-1201",
    notes: "بيانات السكن غير مكتملة",
    updatedAt: "قبل يومين",
    residence: {
      governorate: "المفرق",
      city: "المفرق",
      area: "الحي الشرقي",
      displacementStatus: "نازح",
    },
    members: [
      {
        personCode: "PER-004530",
        fullName: "عائشة عبد الله الحربي",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "FEMALE",
        birthDate: "1988-05-16",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004531",
        fullName: "فهد سعيد الحربي",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2012-08-04",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004532",
        fullName: "غادة سعيد الحربي",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2016-03-23",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001280",
    status: "APPROVED",
    registrationDate: "2026-07-14",
    registrationSource: "MANUAL_ENTRY",
    updatedAt: "قبل 3 أيام",
    residence: {
      governorate: "الزرقاء",
      city: "الزرقاء",
      area: "حي الأمير محمد",
    },
    members: [
      {
        personCode: "PER-004540",
        fullName: "إبراهيم ناصر العتيبي",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "MALE",
        birthDate: "1970-11-11",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004541",
        fullName: "منيرة صالح العتيبي",
        relationship: "SPOUSE",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "1974-04-02",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004542",
        fullName: "سعد إبراهيم العتيبي",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "1996-09-06",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004543",
        fullName: "لمى إبراهيم العتيبي",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2000-01-29",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004544",
        fullName: "ناصر إبراهيم العتيبي",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2003-06-15",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001279",
    status: "PENDING_REVIEW",
    registrationDate: "2026-09-19",
    registrationSource: "IMPORT",
    updatedAt: "قبل 4 أيام",
    residence: {
      governorate: "عمّان",
      city: "الرصيفة",
      area: "حي الجندي",
    },
    members: [
      {
        personCode: "PER-004550",
        fullName: "سارة محمود الزين",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "FEMALE",
        birthDate: "1990-10-03",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004551",
        fullName: "آدم كريم الزين",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2019-02-20",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001278",
    status: "APPROVED",
    registrationDate: "2026-05-30",
    registrationSource: "VERIFIED_SOURCE",
    updatedAt: "قبل أسبوع",
    residence: {
      governorate: "الكرك",
      city: "الكرك",
      area: "وسط البلد",
    },
    members: [
      {
        personCode: "PER-004560",
        fullName: "عمر فيصل الدوسري",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "MALE",
        birthDate: "1968-12-24",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004561",
        fullName: "وفاء طلال الدوسري",
        relationship: "SPOUSE",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "1972-03-18",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004562",
        fullName: "بندر عمر الدوسري",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "1994-07-30",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004563",
        fullName: "منى عمر الدوسري",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "1997-05-12",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004564",
        fullName: "فيصل عمر الدوسري",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2000-11-27",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004565",
        fullName: "ريم عمر الدوسري",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2004-09-09",
        status: "ACTIVE",
      },
      {
        personCode: "PER-004566",
        fullName: "طلال فيصل الدوسري",
        relationship: "FATHER",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "1942-01-15",
        status: "ACTIVE",
      },
    ],
  },
  {
    familyCode: "FAM-001277",
    status: "INACTIVE",
    registrationDate: "2026-02-10",
    registrationSource: "MANUAL_ENTRY",
    notes: "انتقلت الأسرة خارج نطاق التغطية",
    updatedAt: "قبل شهر",
    residence: {
      governorate: "عمّان",
      city: "عمّان",
      area: "ماركا",
    },
    members: [
      {
        personCode: "PER-004570",
        fullName: "ليلى حسن المطيري",
        relationship: "HEAD",
        isHouseholdHead: true,
        gender: "FEMALE",
        birthDate: "1983-04-08",
        status: "INACTIVE",
      },
      {
        personCode: "PER-004571",
        fullName: "سلطان راشد المطيري",
        relationship: "SON",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "2007-08-16",
        status: "INACTIVE",
      },
      {
        personCode: "PER-004572",
        fullName: "شهد راشد المطيري",
        relationship: "DAUGHTER",
        isHouseholdHead: false,
        gender: "FEMALE",
        birthDate: "2010-02-05",
        status: "INACTIVE",
      },
      {
        personCode: "PER-004573",
        fullName: "راشد سلطان المطيري",
        relationship: "FATHER",
        isHouseholdHead: false,
        gender: "MALE",
        birthDate: "1955-10-22",
        status: "INACTIVE",
      },
    ],
  },
];

export function getFamilyByCode(familyCode: string): Family | undefined {
  return families.find(
    (family) => family.familyCode.toLowerCase() === familyCode.toLowerCase()
  );
}

export function getFamilyMemberCount(family: Family): number {
  return family.members.length;
}

export function getMaleCount(family: Family): number {
  return family.members.filter((member) => member.gender === "MALE").length;
}

export function getFemaleCount(family: Family): number {
  return family.members.filter((member) => member.gender === "FEMALE").length;
}

export function calculateAge(birthDate: string): number {
  const birth = new Date(birthDate);
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const monthDiff = today.getMonth() - birth.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
    age -= 1;
  }
  return age;
}

export function getHouseholdHead(family: Family) {
  return family.members.find((member) => member.isHouseholdHead);
}
