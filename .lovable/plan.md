# Life OS সদস্য প্যানেল ও PHP/MySQL সার্ভার

## কী তৈরি হবে
- দেওয়া UI কিটের রং, ফন্ট, মাপ, অবস্থান, টেবিল, মেনু ও ডেস্কটপ/মোবাইল বিন্যাস অনুসরণ করে সদস্য প্যানেল তৈরি হবে। শুধু Life OS-এর ব্র্যান্ড ও সংশ্লিষ্ট তথ্য বদলাবে; নতুন নকশা চাপিয়ে দেওয়া হবে না।
- অ্যাকাউন্ট, প্রোফাইল, পাসওয়ার্ড, নোটিশ, কমিশন রিপোর্ট, দুটি নেটওয়ার্ক ট্রি, রেফারেল, লেনদেন, উত্তোলন ও কিটে থাকা অন্যান্য প্রযোজ্য পৃষ্ঠা থাকবে। সেবার জন্য প্রয়োজনীয় সংযোগ না থাকলে সফল হওয়ার ভুয়া বার্তা দেখানো হবে না।
- বর্তমান পাবলিক সাইটের নকশা অক্ষুণ্ণ থাকবে। মার্কেটিং প্ল্যান ও সদস্যের আর্থিক তথ্য শুধু যাচাইকৃত লগইনের পরে দেখা যাবে।

## সার্ভার ও পুরনো ডেটা
- প্রকল্পের মূল স্থানে `backendserver/` ফোল্ডারে আলাদাভাবে হোস্ট করার PHP REST API, MySQL migrations, পরীক্ষাগুলো ও ইনস্টল নির্দেশনা থাকবে। ওয়েবসাইটের বর্তমান TanStack কাঠামো অক্ষুণ্ণ থাকবে।
- পুরনো স্কিমাকে ভিত্তি ধরে সদস্যের ID, স্পন্সর, placement ও হিসাবের পরিচয় সংরক্ষণ করা হবে। পুরনো ইতিহাস মুছে বা নতুন হিসাব হিসেবে পুনরায় চালিয়ে মাইগ্রেশন হবে না।
- নতুন আর্থিক হিসাবের নির্ভুলতা পূর্ণ countdown রাখার উপযোগী হবে; পুরনো দুই দশমিকের লেজার সরাসরি বদলে না দিয়ে যাচাইযোগ্য mapping ও reconciliation থাকবে।
- লগইন, logout, session মেয়াদ, password reset এবং সদস্য/অ্যাডমিন অনুমতি পৃথক থাকবে। গোপন key শুধু সার্ভারে থাকবে।

## মার্কেটিং হিসাব
- সফল ও সার্ভার-যাচাইকৃত ৳১,০০০ payment ছাড়া activation বা commission হবে না। বরাদ্দ: cashback ১০০, referral ২০০, matching ৩০০, generation ২০০, rank/reward ২০০ টাকা।
- Cashback cash withdrawal/transfer করা যাবে না। Direct referral শুধু সরাসরি স্পন্সরের; generation-এর দশ ধাপ আলাদা হিসাব হবে।
- Binary ও enrollment হিসাব আলাদা; দুটি eligible bill-এর বড়টি বেছে একই event-এ একবার payout হবে। Amount ওয়েবসাইট থেকে গ্রহণ করা হবে না।
- Sale-এর স্থায়ী counter সর্বোচ্চ ১১; প্রথম ৳১৫০, পরের ধাপে অর্ধেক, মোট ৳২৯৯.৮৫৩৫১৫৬২৫। প্রদর্শনের rounding যেন বরাদ্দ বাড়িয়ে না দেয়।
- প্রতি active সদস্যের Dhaka দিনের matching cap ৳৫,০০০। Cap পূর্ণ হলে অতিরিক্ত matched volume flush হবে; genuine unmatched volume carry থাকবে।
- Transactions, row locks, unique event IDs, আলাদা ledger, emergency pause এবং audit trail দিয়ে concurrent/duplicate payout আটকানো হবে। Refund ও সংশোধন reversal হবে, ইতিহাস delete নয়।
- Rank-এর পরস্পরবিরোধী তালিকা চূড়ান্ত না হওয়া পর্যন্ত সংশ্লিষ্ট reward payout বন্ধ থাকবে; কোনো তালিকা অনুমান করে অর্থ দেওয়া হবে না।

## নিরাপদ Master Worker API
- সাধারণ সদস্য API থেকে সম্পূর্ণ পৃথক deployment/admin ব্যবস্থা থাকবে। Browser থেকে server key পাঠানো হবে না।
- অনুমোদিত project folder-এ file/folder তৈরি, পড়া, পরিবর্তন ও মুছে ফেলার ব্যবস্থা; path traversal, symlink escape ও গোপন config পড়া নিষিদ্ধ থাকবে।
- অনুমোদিত database ও table-এ সীমিত প্রশাসনিক কাজ থাকবে। Financial history মুছে দেওয়া বা নির্বিচারে public SQL/shell চালানোর endpoint থাকবে না। Database creation/deletion আলাদা privileged অনুমতি ও স্পষ্ট approval চাইবে।
- Update batch প্রস্তুত, validate ও stage হবে; signed request, timestamp/nonce, checksum, replay protection, backup, audit এবং rollback থাকবে। MySQL schema পরিবর্তন ও file release এক transaction হিসেবে নিশ্চয়তা না দিয়ে প্রয়োজনে আলাদা recovery ধাপ থাকবে।
- কাজ শেষে অনুমোদিত batch এক ধাপে live server-এ পাঠানোর উপযোগী PHP command থাকবে। এই API থাকলেই ভবিষ্যতের প্রতিটি কথোপকথনে স্বয়ংক্রিয় পাঠানো নিশ্চিত হয় না; server connection ও execution access লাগবে।

## পরীক্ষা ও লাইভ চালু করা
- বাস্তব MySQL-এ duplicate notification, midnight, parallel workers, reserve exhaustion, inactive contribution, cashback নিষেধাজ্ঞা, cap overflow, wallet failure rollback ও statement closing-এর পরীক্ষা থাকবে।
- Reference screenshot-এর সঙ্গে desktop/mobile তুলনা এবং login → account → রিপোর্ট → logout পরীক্ষা হবে।
- Live payout প্রথমে বন্ধ থাকবে। Historical reconciliation, নিয়মের অনুমোদন ও গুরুত্বপূর্ণ পরীক্ষা সফল হওয়ার পর চালু করা যাবে।

## বর্তমান সীমা
- UI কিট পাওয়া গেছে; এতে HTML capture, PNG ও PDF আছে, পূর্ণ PHP source নেই। কিছু পৃষ্ঠা ছবি দেখে পুনর্গঠন করতে হবে।
- পুরনো Drive source folder-এর ভেতরের ফাইল এখনো পড়া যায়নি; ZIP পেলে পুনর্ব্যবহারের কাজ করা যাবে।
- এই Lovable preview PHP/MySQL চালায় না। এখানে কোড রাখা যাবে; চালানো ও live পরীক্ষা করতে আলাদা PHP/MySQL hosting-এর HTTPS ঠিকানা ও নিরাপদ connection প্রয়োজন। এই পরিকল্পনা PHP hosting বা live connection ইতোমধ্যে চালু আছে বলে দাবি করছে না।
- Rank নাম ও matching threshold-এ বিরোধ আছে, বিশেষত Global Ambassador ১৫,০০০ বনাম ১৫,০০,০০০। চূড়ান্ত উত্তর না পাওয়া পর্যন্ত reward engine payout-disabled থাকবে।
- Payment gateway ও SMS/email provider নির্দিষ্ট নয়; তাদের বাস্তব সংযোগ না হওয়া পর্যন্ত payment verification/reset delivery পরীক্ষা অসম্পূর্ণ থাকবে।