<?php
defined('ABSPATH') || exit;

final class BTL_Admin_Gold
{
    public static function boot(): void
    {
        add_action('graphql_register_types', [self::class, 'register'], 13);
    }

    private static function assertPermission(string $permission): void
    {
        if (!BTL_Admin_Permissions::can(get_current_user_id(), $permission)) {
            throw new GraphQL\Error\UserError('دسترسی غیرمجاز.');
        }
    }

    public static function register(): void
    {
        register_graphql_object_type('BtlGoldBuyOrder', [
            'fields' => [
                'databaseId'=>['type'=>'Int'],'gameSlug'=>['type'=>'String'],'gameName'=>['type'=>'String'],'region'=>['type'=>'String'],'amount'=>['type'=>'Int'],'offerAmount'=>['type'=>'String'],'ratePer1k'=>['type'=>'String'],'timerMinutes'=>['type'=>'Int'],'status'=>['type'=>'String'],'createdBy'=>['type'=>'Int'],'createdAt'=>['type'=>'String'],'updatedAt'=>['type'=>'String'],'proposalCount'=>['type'=>'Int'],'pendingProposalCount'=>['type'=>'Int'],
            ],
        ]);
        register_graphql_object_type('BtlGoldProposal', [
            'fields' => [
                'databaseId'=>['type'=>'Int'],'buyOrderId'=>['type'=>'Int'],'userId'=>['type'=>'Int'],'profileId'=>['type'=>'String'],'profileName'=>['type'=>'String'],'amount'=>['type'=>'Int'],'status'=>['type'=>'String'],'claimedBy'=>['type'=>'Int'],'claimedAt'=>['type'=>'String'],'claimExpiresAt'=>['type'=>'String'],'suspendedReason'=>['type'=>'String'],'completedAt'=>['type'=>'String'],'paidAt'=>['type'=>'String'],'createdAt'=>['type'=>'String'],'updatedAt'=>['type'=>'String'],'gameName'=>['type'=>'String'],'gameSlug'=>['type'=>'String'],'region'=>['type'=>'String'],'buyAmount'=>['type'=>'Int'],'offerAmount'=>['type'=>'String'],
            ],
        ]);
        register_graphql_object_type('BtlGoldDeal', [
            'fields' => [
                'databaseId'=>['type'=>'Int'],'buyOrderId'=>['type'=>'Int'],'proposalId'=>['type'=>'Int'],'sellerUserId'=>['type'=>'Int'],'sellerName'=>['type'=>'String'],'sellerEmail'=>['type'=>'String'],'profileName'=>['type'=>'String'],'proposalAmount'=>['type'=>'Int'],'status'=>['type'=>'String'],'timerExpiresAt'=>['type'=>'String'],'deliveredAmount'=>['type'=>'Int'],'deliveryConfirmedAt'=>['type'=>'String'],'suspendedReason'=>['type'=>'String'],'gameName'=>['type'=>'String'],'gameSlug'=>['type'=>'String'],'region'=>['type'=>'String'],'offerAmount'=>['type'=>'String'],
            ],
        ]);
        register_graphql_object_type('BtlGoldPayout', [
            'fields'=>['databaseId'=>['type'=>'Int'],'dealId'=>['type'=>'Int'],'proposalId'=>['type'=>'Int'],'userId'=>['type'=>'Int'],'amount'=>['type'=>'String'],'status'=>['type'=>'String'],'adminUserId'=>['type'=>'Int'],'note'=>['type'=>'String'],'paidAt'=>['type'=>'String']],
        ]);

        register_graphql_field('RootQuery','adminGoldBuyOrders',[
            'type'=>['list_of'=>'BtlGoldBuyOrder'],
            'args'=>['status'=>['type'=>'String'],'gameSlug'=>['type'=>'String'],'first'=>['type'=>'Int']],
            'resolve'=>static function($root,array $args):array{self::assertPermission('gold.read');return BTL_Gold_Market::listAdmin($args);},
        ]);
        register_graphql_field('RootQuery','adminGoldProposals',[
            'type'=>['list_of'=>'BtlGoldProposal'],
            'args'=>['buyOrderId'=>['type'=>'Int'],'status'=>['type'=>'String'],'first'=>['type'=>'Int']],
            'resolve'=>static function($root,array $args):array{self::assertPermission('gold.read');return BTL_Gold_Market::proposalQueue((int)($args['buyOrderId']??0),sanitize_key((string)($args['status']??'all')),(int)($args['first']??30));},
        ]);
        register_graphql_field('RootQuery','adminGoldDeals',[
            'type'=>['list_of'=>'BtlGoldDeal'],
            'args'=>['status'=>['type'=>'String'],'first'=>['type'=>'Int']],
            'resolve'=>static function($root,array $args):array{self::assertPermission('gold.read');return BTL_Gold_Market::listDeals(sanitize_key((string)($args['status']??'active')),(int)($args['first']??20));},
        ]);
        register_graphql_field('RootQuery','adminGoldDeal',[
            'type'=>'BtlGoldDeal',
            'args'=>['id'=>['type'=>['non_null'=>'Int']]],
            'resolve'=>static function($root,array $args):?array{self::assertPermission('gold.read');return BTL_Gold_Market::getDeal((int)$args['id']);},
        ]);

        register_graphql_mutation('adminCreateGoldBuyOrder',[
            'inputFields'=>[
                'gameSlug'=>['type'=>['non_null'=>'String']],'gameName'=>['type'=>['non_null'=>'String']],'region'=>['type'=>['non_null'=>'String']],'amount'=>['type'=>['non_null'=>'Int']],'offerAmount'=>['type'=>['non_null'=>'String']],'ratePer1k'=>['type'=>'String'],'timerMinutes'=>['type'=>'Int'],
            ],
            'outputFields'=>['success'=>['type'=>'Boolean'],'buyOrder'=>['type'=>'BtlGoldBuyOrder']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.write');$id=BTL_Gold_Market::createBuyOrder($input);return ['success'=>true,'buyOrder'=>BTL_Gold_Market::getBuyOrder($id)];},
        ]);
        register_graphql_mutation('adminClaimGoldProposal',[
            'inputFields'=>['proposalId'=>['type'=>['non_null'=>'Int']]],
            'outputFields'=>['success'=>['type'=>'Boolean'],'proposal'=>['type'=>'BtlGoldProposal']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.claim');return ['success'=>true,'proposal'=>BTL_Gold_Market::claimProposal((int)$input['proposalId'])];},
        ]);
        register_graphql_mutation('adminStartGoldDeal',[
            'inputFields'=>['proposalId'=>['type'=>['non_null'=>'Int']]],
            'outputFields'=>['success'=>['type'=>'Boolean'],'deal'=>['type'=>'BtlGoldDeal']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.claim');$deal=BTL_Gold_Market::startDeal((int)$input['proposalId']);return ['success'=>true,'deal'=>$deal];},
        ]);
        register_graphql_mutation('adminUpdateGoldDealStatus',[
            'inputFields'=>['dealId'=>['type'=>['non_null'=>'Int']],'status'=>['type'=>['non_null'=>'String']],'reason'=>['type'=>'String']],
            'outputFields'=>['success'=>['type'=>'Boolean'],'deal'=>['type'=>'BtlGoldDeal']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.write');return ['success'=>true,'deal'=>BTL_Gold_Market::updateDealStatus((int)$input['dealId'],sanitize_key((string)$input['status']),isset($input['reason'])?(string)$input['reason']:null)];},
        ]);
        register_graphql_mutation('adminConfirmGoldReceived',[
            'inputFields'=>['dealId'=>['type'=>['non_null'=>'Int']],'amount'=>['type'=>'Int']],
            'outputFields'=>['success'=>['type'=>'Boolean'],'deal'=>['type'=>'BtlGoldDeal']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.write');return ['success'=>true,'deal'=>BTL_Gold_Market::confirmReceived((int)$input['dealId'],(int)($input['amount']??0))];},
        ]);
        register_graphql_mutation('adminRecordGoldPayout',[
            'inputFields'=>['dealId'=>['type'=>['non_null'=>'Int']],'amount'=>['type'=>['non_null'=>'String']],'note'=>['type'=>'String']],
            'outputFields'=>['success'=>['type'=>'Boolean'],'payout'=>['type'=>'BtlGoldPayout']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.payout');$dealId=(int)$input['dealId'];$payout=BTL_Gold_Market::recordPayout($dealId,(string)$input['amount'],isset($input['note'])?(string)$input['note']:null);return ['success'=>true,'payout'=>$payout];},
        ]);
        register_graphql_mutation('adminAddGoldStrike',[
            'inputFields'=>['userId'=>['type'=>['non_null'=>'Int']],'reason'=>['type'=>['non_null'=>'String']]],
            'outputFields'=>['success'=>['type'=>'Boolean'],'strikeCount'=>['type'=>'Int']],
            'mutateAndGetPayload'=>static function(array $input):array{self::assertPermission('gold.write');return ['success'=>true,'strikeCount'=>BTL_Gold_Market::addStrike((int)$input['userId'],(string)$input['reason'])];},
        ]);

        register_graphql_field('RootQuery','goldBuyRequests',[
            'type'=>['list_of'=>'BtlGoldBuyOrder'],
            'args'=>['first'=>['type'=>'Int']],
            'resolve'=>static function($root,array $args):array{return BTL_Gold_Market::activeBuyOrdersForUsers((int)($args['first']??20));},
        ]);
        register_graphql_mutation('submitGoldProposal',[
            'inputFields'=>['buyOrderId'=>['type'=>['non_null'=>'Int']],'amount'=>['type'=>['non_null'=>'Int']],'profileId'=>['type'=>'String'],'profileName'=>['type'=>['non_null'=>'String']]],
            'outputFields'=>['success'=>['type'=>'Boolean'],'proposalId'=>['type'=>'Int']],
            'mutateAndGetPayload'=>static function(array $input):array{$id=BTL_Gold_Market::submitProposal((int)$input['buyOrderId'],(string)$input['profileName'],(int)$input['amount'],isset($input['profileId'])?(string)$input['profileId']:null);return ['success'=>true,'proposalId'=>$id];},
        ]);
    }
}
