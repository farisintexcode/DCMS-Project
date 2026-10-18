<?php

namespace Database\Seeders;

use App\Models\ReferenceValue;
use Illuminate\Database\Seeder;

/** M06 approved by owner 17 Oct 2026; preserve subsequent authorized master-data edits. */
class RiderReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $sets = [
            'availability' => ['available' => 'Available', 'not_available' => 'Not Available', 'temporarily_away' => 'Temporarily Away', 'premises_closed' => 'Premises Closed', 'unable_to_confirm' => 'Unable to Confirm'],
            'visit_status' => ['operating' => 'Operating', 'temporarily_closed' => 'Temporarily Closed', 'permanently_closed' => 'Permanently Closed', 'vacant' => 'Vacant', 'relocated' => 'Relocated', 'address_not_found' => 'Address Not Found', 'access_restricted' => 'Access Restricted', 'unable_to_verify' => 'Unable to Verify'],
            'contact_result' => ['customer_contacted' => 'Customer Contacted', 'representative_contacted' => 'Representative Contacted', 'no_answer' => 'No Answer', 'phone_unreachable' => 'Phone Unreachable', 'refused_to_engage' => 'Refused to Engage', 'wrong_contact' => 'Wrong Contact Information', 'customer_not_present' => 'Customer Not Present', 'call_back_requested' => 'Call Back Requested', 'unable_to_contact' => 'Unable to Contact'],
            'activity_outcome' => ['successful_visit' => 'Successful Visit', 'promise_to_pay' => 'Promise to Pay', 'payment_claimed' => 'Payment Made / Payment Claimed', 'follow_up_required' => 'Follow-Up Required', 'disputes_debt' => 'Customer Disputes Debt', 'refused_payment' => 'Customer Refused Payment', 'customer_not_available' => 'Customer Not Available', 'premises_closed' => 'Premises Closed', 'customer_relocated' => 'Customer Relocated', 'invalid_address' => 'Address Invalid / Not Found', 'unable_to_complete' => 'Unable to Complete Visit', 'escalation_required' => 'Escalation Required'],
            'business_type' => ['sole_proprietorship' => 'Sole Proprietorship', 'partnership' => 'Partnership', 'private_limited' => 'Private Limited Company', 'public_limited' => 'Public Limited Company', 'government' => 'Government / Agency', 'association' => 'Association / Organization', 'individual' => 'Individual / Personal', 'other' => 'Other'],
            'business_nature' => ['retail' => 'Retail', 'food_beverage' => 'Food & Beverage', 'services' => 'Services', 'manufacturing' => 'Manufacturing', 'construction' => 'Construction', 'logistics' => 'Logistics / Transportation', 'automotive' => 'Automotive', 'healthcare' => 'Healthcare', 'education' => 'Education', 'professional_services' => 'Professional Services', 'technology' => 'Technology / IT', 'property' => 'Property / Real Estate', 'agriculture' => 'Agriculture', 'wholesale' => 'Wholesale / Distribution', 'other' => 'Other'],
            'business_status' => ['active' => 'Active', 'temporarily_suspended' => 'Temporarily Suspended', 'closed' => 'Closed', 'relocated' => 'Relocated', 'under_renovation' => 'Under Renovation', 'unable_to_verify' => 'Unable to Verify', 'other' => 'Other'],
            'image_category' => ['premises' => 'Premises / Site Photo', 'business_signboard' => 'Business Signboard', 'customer_contact' => 'Customer / Contact Evidence', 'supporting_document' => 'Supporting Document', 'payment_evidence' => 'Payment Evidence', 'other_evidence' => 'Other Supporting Evidence'],
            'visit_purpose' => ['site_visit' => 'Normal Site Visit', 'address_verification' => 'Address Verification', 'collection_visit' => 'Collection Visit', 'payment_verification' => 'Payment Verification', 'invalid_address' => 'Customer Not Found / Invalid Address'],
        ];
        foreach ($sets as $category => $values) {
            foreach ($values as $code => $label) {
                ReferenceValue::query()->firstOrCreate(['category' => $category, 'code' => $code], ['label' => $label, 'metadata' => $category === 'visit_purpose' ? [
                    'version' => 'm06-v1', 'backdate_calendar_days' => 1, 'min_images' => 1, 'required_categories' => [$code === 'payment_verification' ? 'payment_evidence' : 'premises'],
                    'required_remarks' => in_array($code, ['site_visit', 'collection_visit', 'payment_verification', 'invalid_address'], true) ? ['visit'] : [],
                    'business_required' => false, 'signboard_when_available' => $code === 'address_verification',
                ] : []]);
            }
        }
        foreach (ReferenceValue::query()->where('category', 'assignment_type')->whereIn('code', ['vacant', 'domestic_dr', 'domestic_ctos', 'domestic_lod', 'domestic_lod_ctos', 'com_dr', 'com_lod'])->get() as $type) {
            $metadata = $type->metadata ?? [];
            if (! array_key_exists('execution_policy', $metadata)) {
                $metadata['execution_policy'] = ['version' => 'm06-v1', 'purpose_codes' => array_keys($sets['visit_purpose']), 'overrides' => []];
                $type->metadata = $metadata;
                $type->save();
            }
        }
    }
}
